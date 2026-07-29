<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ReportType;
use App\Models\PendingExport;
use Carbon\CarbonImmutable;

/**
 * A resolved report request: what to report on, over what period, for which branch.
 *
 * `pending_exports.parameters` is loose JSON written by three different callers
 * (the report form, a saved definition's scheduled run, and tests). This is the one
 * place that turns it into typed values, so the on-screen page and the queued export
 * cannot disagree about which month "last month" was.
 */
final readonly class ReportRequest
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public ReportType $type,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $branchId,
        public array $parameters = [],
    ) {}

    public static function fromPendingExport(PendingExport $export): self
    {
        /** @var array<string, mixed> $parameters */
        $parameters = $export->parameters ?? [];

        // A parameters key wins over the row's own branch_id: a user with
        // branches.view_all can run "All branches" from a branch they belong to,
        // and the null they chose must survive.
        $branchId = array_key_exists('branch_id', $parameters)
            ? $parameters['branch_id']
            : $export->branch_id;

        return new self(
            type: $export->report_type,
            from: self::parse($parameters['from'] ?? null) ?? CarbonImmutable::today()->startOfMonth(),
            to: self::parse($parameters['to'] ?? null) ?? CarbonImmutable::today()->endOfMonth(),
            branchId: $branchId === null ? null : (int) $branchId,
            parameters: $parameters,
        );
    }

    /**
     * The id of the entity this report is narrowed to, or null for the whole set.
     */
    public function scopeId(): ?int
    {
        $field = $this->type->scopeField();

        if ($field === null) {
            return null;
        }

        $value = $this->parameters[$field] ?? null;

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value);
    }
}
