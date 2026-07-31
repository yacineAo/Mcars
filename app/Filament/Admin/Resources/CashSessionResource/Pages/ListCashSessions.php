<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CashSessionResource\Pages;

use App\Filament\Admin\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Services\CashRegisterService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ListCashSessions extends ListRecords
{
    protected static string $resource = CashSessionResource::class;

    /** @var array<int, array{expected: string, variance: ?string}> */
    protected array $reconciliations = [];

    /** @var array<int, int> */
    protected array $reconciliationsFor = [];

    /**
     * Expected/variance for the rows currently in the table, computed once per
     * distinct set of rows. The table renders one row at a time, so the columns
     * cannot run a service call per row — that would be two aggregate queries
     * per visible row. Recomputes when lazy loading brings more rows in.
     *
     * @return array<int, array{expected: string, variance: ?string}> keyed by session id
     */
    public function getReconciliations(): array
    {
        $records = $this->getTable()->getRecords();

        /** @var Collection<int, CashSession> $sessions */
        $sessions = $records instanceof LengthAwarePaginator ? $records->getCollection() : $records;

        /** @var array<int, int> $ids */
        $ids = $sessions->map(fn (CashSession $session): int => (int) $session->id)->all();

        if ($ids !== $this->reconciliationsFor) {
            $this->reconciliations = app(CashRegisterService::class)->reconciliations($sessions);
            $this->reconciliationsFor = $ids;
        }

        return $this->reconciliations;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Open Session'),
        ];
    }
}
