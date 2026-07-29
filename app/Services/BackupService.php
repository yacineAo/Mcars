<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Backup integrity verification.
 *
 * Finds the latest backup archive, restores it into a scratch database and
 * asserts table row counts match the production database. A backup that has
 * never been restored is only a hypothesis.
 */
class BackupService
{
    private const SCRATCH_DATABASE = 'mcars_verify';

    public function verifyLatest(): bool
    {
        $latest = $this->latestBackupPath();

        if ($latest === null) {
            Log::warning('BackupService: no backup file found to verify.');

            return false;
        }

        $this->ensureScratchDatabaseDoesNotExist();

        try {
            $this->restoreBackup($latest);
            $this->assertRowCountsMatch();
            Log::info('BackupService: backup verified successfully.', ['path' => $latest]);

            return true;
        } catch (Throwable $e) {
            Log::error('BackupService: verification failed.', [
                'path' => $latest,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $this->dropScratchDatabase();
        }
    }

    private function latestBackupPath(): ?string
    {
        $disk = Storage::disk(Config::get('backup.backup.destination.disks')[0] ?? 'local');
        $files = collect($disk->files())
            ->filter(fn (string $path): bool => str_ends_with($path, '.zip'))
            ->sort()
            ->reverse()
            ->values();

        return $files->first();
    }

    private function ensureScratchDatabaseDoesNotExist(): void
    {
        $pdo = $this->templatePdo();
        $pdo->exec('DROP DATABASE IF EXISTS '.self::SCRATCH_DATABASE);
        $pdo = null;

        Log::debug('BackupService: ensured scratch database does not exist.');
    }

    private function restoreBackup(string $path): void
    {
        $disk = Storage::disk(Config::get('backup.backup.destination.disks')[0] ?? 'local');
        $fullPath = $disk->path($path);
        $tempDir = storage_path('app/backup-temp/verify-'.now()->timestamp);

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($fullPath) !== true) {
            throw new RuntimeException("Cannot open backup archive: {$fullPath}");
        }

        $zip->extractTo($tempDir);
        $zip->close();

        $files = scandir($tempDir);
        $dumpFile = null;

        foreach ($files as $file) {
            if (str_ends_with((string) $file, '.sql') || str_ends_with((string) $file, '.dump')) {
                $dumpFile = $tempDir.'/'.$file;
                break;
            }
        }

        if ($dumpFile === null) {
            $this->cleanTempDir($tempDir);
            throw new RuntimeException('No SQL dump file found in the backup archive.');
        }

        $this->createScratchDatabase();
        $this->enableExtensions();
        $this->executeDumpFile($dumpFile);
        $this->cleanTempDir($tempDir);

        Log::debug('BackupService: database restored to scratch database.');
    }

    private function createScratchDatabase(): void
    {
        $pdo = $this->templatePdo();
        $pdo->exec('CREATE DATABASE '.self::SCRATCH_DATABASE.' WITH TEMPLATE template0');
        $pdo = null;
    }

    private function enableExtensions(): void
    {
        $pdo = $this->scratchPdo();
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $pdo = null;
    }

    private function dropScratchDatabase(): void
    {
        try {
            $pdo = $this->templatePdo();
            $pdo->exec('DROP DATABASE IF EXISTS '.self::SCRATCH_DATABASE.' WITH (FORCE)');
            $pdo = null;
        } catch (Throwable $e) {
            Log::warning('BackupService: could not drop scratch database.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute a SQL dump file against the scratch database.
     *
     * Splits on semicolons to avoid running the entire dump as a single
     * statement, which can exceed statement-level memory limits on large
     * backups.
     */
    private function executeDumpFile(string $path): void
    {
        $sql = file_get_contents($path);

        if ($sql === false || $sql === '') {
            throw new RuntimeException("Cannot read dump file: {$path}");
        }

        $pdo = $this->scratchPdo();
        $pdo->exec('SET statement_timeout = 0');

        $statements = $this->splitSqlStatements($sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            $pdo->exec($statement);
        }

        $pdo = null;
    }

    /**
     * Split a SQL dump into individual statements.
     *
     * Handles dollar-quoted functions (the immutability trigger), COPY data
     * blocks, and multi-line statements.
     *
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inDollarQuote = false;
        $dollarTag = '';
        $inCopy = false;

        $lines = explode("\n", $sql);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' && ! $inDollarQuote && ! $inCopy) {
                $current .= "\n";

                continue;
            }

            if ($inDollarQuote) {
                $current .= $line."\n";
                if ($dollarTag !== '' && str_contains($trimmed, '$'.$dollarTag.'$')) {
                    $inDollarQuote = false;
                }

                continue;
            }

            if ($inCopy) {
                $current .= $line."\n";
                if ($trimmed === '\\.') {
                    $inCopy = false;
                }

                continue;
            }

            if (preg_match('/^\$(\w*)\$$/', $trimmed, $m)) {
                $dollarTag = $m[1];
                $inDollarQuote = true;
                $current .= $line."\n";

                continue;
            }

            if (str_starts_with(strtoupper($trimmed), 'COPY ')) {
                $inCopy = true;
                $current .= $line."\n";

                continue;
            }

            $current .= $line."\n";

            if (str_ends_with($trimmed, ';')) {
                $statements[] = $current;
                $current = '';
            }
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }

    private function assertRowCountsMatch(): void
    {
        $tables = $this->getTableNames();

        $mismatches = [];

        foreach ($tables as $table) {
            $mainCount = $this->tableRowCount($table, 'mcars');
            $scratchCount = $this->tableRowCount($table, self::SCRATCH_DATABASE);

            if ($mainCount !== $scratchCount) {
                $mismatches[] = "{$table}: main={$mainCount} scratch={$scratchCount}";
            }
        }

        if ($mismatches !== []) {
            throw new RuntimeException(
                'Row count mismatches: '.implode('; ', $mismatches),
            );
        }

        Log::info('BackupService: row counts match across all tables.', [
            'tables_verified' => count($tables),
        ]);
    }

    /** @return list<string> */
    private function getTableNames(): array
    {
        $results = DB::connection('pgsql')
            ->select("SELECT tablename FROM pg_catalog.pg_tables
                      WHERE schemaname = 'public'
                        AND tablename NOT IN ('migrations')
                      ORDER BY tablename");

        /** @var list<string> */
        return array_map(fn ($row): string => $row->tablename, $results);
    }

    private function tableRowCount(string $table, string $database): int
    {
        $pdo = $database === 'mcars'
            ? DB::connection('pgsql')->getPdo()
            : $this->scratchPdo();

        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM \"{$table}\"");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $row['cnt'];
    }

    private function templatePdo(): PDO
    {
        return new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=template1',
                Config::get('database.connections.pgsql.host'),
                Config::get('database.connections.pgsql.port'),
            ),
            Config::get('database.connections.pgsql.username'),
            Config::get('database.connections.pgsql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function scratchPdo(): PDO
    {
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                Config::get('database.connections.pgsql.host'),
                Config::get('database.connections.pgsql.port'),
                self::SCRATCH_DATABASE,
            ),
            Config::get('database.connections.pgsql.username'),
            Config::get('database.connections.pgsql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec('SET search_path TO public');

        return $pdo;
    }

    private function cleanTempDir(string $path): void
    {
        $files = scandir($path);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $full = $path.'/'.$file;
            if (is_file($full)) {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
