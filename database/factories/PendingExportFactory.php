<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Models\PendingExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PendingExportFactory extends Factory
{
    protected $model = PendingExport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => null,
            'report_type' => ReportType::ProfitAndLoss->value,
            'format' => ExportFormat::Pdf->value,
            'parameters' => [
                'from' => '2026-01-01',
                'to' => '2026-01-31',
            ],
            'status' => 'pending',
        ];
    }
}
