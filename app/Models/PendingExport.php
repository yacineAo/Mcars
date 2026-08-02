<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property array<string, mixed> $parameters
 * @property ExportFormat $format
 * @property ReportType $report_type
 * @property-read ReportDefinition|null $reportDefinition
 */
class PendingExport extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'report_definition_id',
        'report_type',
        'format',
        'parameters',
        'file_path',
        'file_name',
        'file_size',
        'status',
        'completed_at',
        'failed_at',
        'error_message',
        'downloaded_at',
    ];

    public function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ExportFormat::class,
            'parameters' => 'json',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reportDefinition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(string $filePath, string $fileName, int $fileSize): void
    {
        $this->update([
            'status' => 'completed',
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'failed_at' => CarbonImmutable::now(),
        ]);
    }

    public function markAsDownloaded(): void
    {
        $this->update(['downloaded_at' => CarbonImmutable::now()]);
    }

    public function downloadUrl(): ?string
    {
        if (! $this->isCompleted() || $this->file_path === null) {
            return null;
        }

        return Storage::disk('private')->url($this->file_path);
    }

    public function tempDownloadUrl(): ?string
    {
        if (! $this->isCompleted() || $this->file_path === null) {
            return null;
        }

        return Storage::disk('private')->temporaryUrl(
            $this->file_path,
            CarbonImmutable::now()->addMinutes(config('mcars.signed_url_ttl', 300)),
        );
    }
}
