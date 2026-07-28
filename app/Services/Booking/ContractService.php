<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\ContractStatus;
use App\Models\Booking;
use App\Models\ConditionReport;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ContractService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SequenceGenerator $sequences,
    ) {}

    public function generate(Booking $booking, ?ContractTemplate $template = null, string $locale = 'fr'): Contract
    {
        if ($booking->contract !== null) {
            throw new RuntimeException('A contract already exists for this booking.');
        }

        if ($template === null) {
            $template = ContractTemplate::query()
                ->where('locale', $locale)
                ->where('is_active', true)
                ->where('is_default', true)
                ->first();

            if ($template === null) {
                $template = ContractTemplate::query()
                    ->where('locale', $locale)
                    ->where('is_active', true)
                    ->first();
            }
        }

        return $this->db->transaction(function () use ($booking, $template): Contract {
            $contractNumber = $this->sequences->next('contract', $booking->branch_id);

            $snapshot = $this->buildSnapshot($booking, $template);

            $contract = Contract::create([
                'uuid' => (string) Str::uuid(),
                'contract_number' => $contractNumber,
                'branch_id' => $booking->branch_id,
                'booking_id' => $booking->id,
                'car_id' => $booking->car_id,
                'customer_id' => $booking->customer_id,
                'contract_template_id' => $template?->id,
                'status' => ContractStatus::Draft,
                'content_snapshot' => $snapshot,
                'terms_version' => $template?->terms_version ?? '1.0',
                'generated_at' => now(),
                'has_damages' => false,
            ]);

            return $contract;
        });
    }

    public function renderPdf(Contract $contract): string
    {
        $html = $this->renderHtml($contract);
        $disk = 'private';
        $path = 'contracts/'.$contract->contract_number.'.pdf';

        Storage::disk($disk)->put($path, $html);

        $contract->pdf_disk = $disk;
        $contract->pdf_path = $path;
        $contract->document_hash = hash('sha256', $html);
        $contract->save();

        return $path;
    }

    public function send(Contract $contract, array $channels = ['mail'], ?string $to = null): void
    {
        $contract->sent_at = now();
        $contract->sent_channel = implode(',', $channels);
        $contract->sent_to = $to;
        $contract->status = ContractStatus::AwaitingSignature;
        $contract->save();
    }

    public function close(Contract $contract, ConditionReport $checkin, User $by): Contract
    {
        if ($contract->status !== ContractStatus::Active) {
            throw new RuntimeException('Only active contracts can be closed.');
        }

        $contract->status = ContractStatus::Closed;
        $contract->closed_at = now();
        $contract->closed_by_id = $by->id;
        $contract->has_damages = ! $checkin->is_clean || count($checkin->damage_points ?? []) > 0;
        $contract->closing_notes = $checkin->notes;
        $contract->save();

        return $contract;
    }

    public function amend(Contract $contract, array $changes): Contract
    {
        $newSnapshot = array_merge($contract->content_snapshot ?? [], $changes);

        $amended = Contract::create([
            'uuid' => (string) Str::uuid(),
            'contract_number' => $this->sequences->next('contract', $contract->branch_id),
            'branch_id' => $contract->branch_id,
            'booking_id' => $contract->booking_id,
            'car_id' => $contract->car_id,
            'customer_id' => $contract->customer_id,
            'contract_template_id' => $contract->contract_template_id,
            'status' => ContractStatus::Amended,
            'content_snapshot' => $newSnapshot,
            'terms_version' => $contract->terms_version,
            'generated_at' => now(),
            'has_damages' => $contract->has_damages,
            'parent_contract_id' => $contract->id,
        ]);

        return $amended;
    }

    private function buildSnapshot(Booking $booking, ?ContractTemplate $template): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'booking_reference' => $booking->reference,
            'contract_number' => null,
            'locale' => $template?->locale ?? 'fr',
            'terms_version' => $template?->terms_version ?? '1.0',
            'template_body' => $template?->body ?? null,
            'customer' => [
                'id' => $booking->customer->id,
                'name' => $booking->customer->first_name.' '.$booking->customer->last_name,
                'national_id' => $booking->customer->national_id,
                'phone' => $booking->customer->phone,
                'address' => $booking->customer->address,
                'city' => $booking->customer->city,
                'wilaya' => $booking->customer->wilaya,
            ],
            'car' => [
                'id' => $booking->car->id,
                'brand' => $booking->car->brand,
                'model' => $booking->car->model,
                'year' => $booking->car->year,
                'color' => $booking->car->color,
                'registration_number' => $booking->car->registration_number,
                'chassis_number' => $booking->car->chassis_number,
            ],
            'pricing' => [
                'daily_rate' => $booking->daily_rate,
                'days_count' => $booking->days_count,
                'subtotal' => $booking->subtotal,
                'extras_total' => $booking->extras_total,
                'discount_amount' => $booking->discount_amount,
                'tax_amount' => $booking->tax_amount,
                'total_amount' => $booking->total_amount,
                'security_deposit_amount' => $booking->security_deposit_amount,
            ],
            'period' => [
                'pickup_at' => $booking->pickup_at?->toIso8601String(),
                'expected_return_at' => $booking->expected_return_at?->toIso8601String(),
            ],
            'extras' => $booking->extras->map(fn ($be) => [
                'name' => $be->extra?->name,
                'quantity' => $be->quantity,
                'unit_price' => $be->unit_price,
                'total' => $be->total,
            ])->toArray(),
            'additional_drivers' => $booking->additionalDrivers->map(fn ($ad) => [
                'full_name' => $ad->full_name,
                'driving_license_number' => $ad->driving_license_number,
            ])->toArray(),
        ];
    }

    private function renderHtml(Contract $contract): string
    {
        $snapshot = $contract->content_snapshot;
        $customer = $snapshot['customer'] ?? [];
        $car = $snapshot['car'] ?? [];
        $pricing = $snapshot['pricing'] ?? [];

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>Contract '.$contract->contract_number.'</title>';
        $html .= '<style>body{font-family:sans-serif;margin:40px}';
        $html .= 'h1{color:#1a56db}table{width:100%;border-collapse:collapse}';
        $html .= 'td,th{padding:8px;border:1px solid #ddd;text-align:left}';
        $html .= '.total{font-weight:bold;font-size:1.2em}</style></head><body>';
        $html .= '<h1>Contract '.$contract->contract_number.'</h1>';
        $html .= '<h2>Customer</h2><p>'.($customer['name'] ?? '').'</p>';
        $html .= '<p>ID: '.($customer['national_id'] ?? '').' | Phone: '.($customer['phone'] ?? '').'</p>';
        $html .= '<h2>Vehicle</h2><p>'.$car['brand'] ?? ''.' '.$car['model'] ?? ''.' ('.$car['year'] ?? ''.')</p>';
        $html .= '<p>Reg: '.$car['registration_number'] ?? ''.' | Chassis: '.$car['chassis_number'] ?? ''.'</p>';
        $html .= '<h2>Terms</h2>';
        $html .= '<p>'.$snapshot['template_body'] ?? 'Standard terms apply.'.'</p>';
        $html .= '<h2>Pricing</h2>';
        $html .= '<table><tr><th>Item</th><th>Amount</th></tr>';
        $html .= '<tr><td>Daily Rate × '.($pricing['days_count'] ?? 0).' days</td>';
        $html .= '<td>'.$pricing['subtotal'] ?? '0.00'.' DZD</td></tr>';
        $html .= '<tr class="total"><td>Total</td><td>'.$pricing['total_amount'] ?? '0.00'.' DZD</td></tr>';
        $html .= '</table></body></html>';

        return $html;
    }
}
