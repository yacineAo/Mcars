<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\ContractStatus;
use App\Enums\SignatureMethod;
use App\Enums\SignerRole;
use App\Models\Booking;
use App\Models\ConditionReport;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use App\Support\Sequences\SequenceGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use DOMDocument;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

        $template ??= $this->resolveTemplate($locale, $booking->branch_id);

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

    /**
     * Which template a contract in this locale renders from.
     *
     * `contract_templates.branch_id` is nullable, and null means "available to every
     * branch" (docs/01-database-schema.md) — so a branch sees its own templates plus
     * the global ones. An explicit default wins; between two defaults the branch's own
     * beats the global one; `id` breaks the remaining tie so the choice is never
     * arbitrary. Returns null when the locale has no active template at all, which
     * `generate()` handles by falling back to a bare snapshot.
     */
    public function resolveTemplate(string $locale, ?int $branchId): ?ContractTemplate
    {
        return ContractTemplate::query()
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereNull('branch_id');

                if ($branchId !== null) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByDesc('is_default')
            ->orderByRaw('branch_id IS NULL')
            ->orderBy('id')
            ->first();
    }

    /**
     * Make this template the sole default for its branch and locale.
     *
     * The exclusivity rule lives here rather than in the Filament pages that trigger
     * it: it is a business rule, and three copies of it in the panel is how the edit
     * page came to clear the wrong locale. Scoped by branch as well as locale, because
     * a template carries a `branch_id` and one branch must not silently strip another
     * branch's default.
     */
    public function setDefaultTemplate(ContractTemplate $template): ContractTemplate
    {
        return $this->db->transaction(function () use ($template): ContractTemplate {
            ContractTemplate::query()
                ->where('locale', $template->locale->value)
                ->where(function (Builder $query) use ($template): void {
                    $template->branch_id === null
                        ? $query->whereNull('branch_id')
                        : $query->where('branch_id', $template->branch_id);
                })
                ->whereKeyNot($template->getKey())
                ->update(['is_default' => false]);

            if (! $template->is_default) {
                $template->forceFill(['is_default' => true])->save();
            }

            return $template;
        });
    }

    /**
     * Renders and stores the contract PDF, and stamps `document_hash` from the
     * actual PDF bytes — ADR-009 calls those bytes "legally significant", so the
     * hash has to fingerprint what a customer would actually receive, not the HTML
     * that built it.
     */
    public function renderPdf(Contract $contract): string
    {
        $pdf = Pdf::setOptions([
            'defaultFont' => 'dejavu sans',
            // The template is self-contained — inline <style>, a bundled font, no
            // <img>/<link> (sanitizeDocumentHtml()'s allow-list drops both anyway).
            // Remote fetching stays off so a future allow-list change can't turn
            // staff-authored template bodies into an SSRF vector.
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ])->loadView('contracts.pdf', [
            'contract' => $contract,
            'documentHtml' => $this->renderDocumentHtml($contract),
            'direction' => $contract->direction(),
        ]);

        $bytes = $pdf->output();
        $disk = 'private';
        $path = 'contracts/'.$contract->contract_number.'.pdf';

        Storage::disk($disk)->put($path, $bytes);

        $contract->pdf_disk = $disk;
        $contract->pdf_path = $path;
        $contract->document_hash = hash('sha256', $bytes);
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
        if (! $contract->status->is(ContractStatus::Active, ContractStatus::Signed)) {
            throw new RuntimeException('Only active or signed contracts can be closed.');
        }

        if ($checkin->booking_id !== $contract->booking_id) {
            throw new RuntimeException('The check-in report does not belong to this contract\'s booking.');
        }

        $contract->status = ContractStatus::Closed;
        $contract->closed_at = now();
        $contract->closed_by_id = $by->id;
        $contract->has_damages = ! $checkin->is_clean || count($checkin->damage_points ?? []) > 0;
        $contract->closing_notes = $checkin->notes;
        $contract->save();

        return $contract;
    }

    /**
     * Record an in-person signature and freeze the contract.
     *
     * The signing staff member vouches for the signer's identity, so the signature is
     * recorded with the signer's name from the document itself and the witness's id —
     * the audit trail must answer *who* vouched, not just *whose* signature went on
     * (ADR-001). The terms were already frozen by the snapshot; marking it signed is
     * what locks the document against editing (ADR-005).
     *
     * Both writes — the signature row and the status transition — run in one
     * transaction with the contract row locked, so two desks signing at once cannot
     * record two signatures for one contract.
     *
     * @throws RuntimeException when the contract is already signed, closed or cancelled.
     */
    public function markSigned(Contract $contract, SignerRole $signerRole, string $signerName, User $by, ?Model $signer = null): Contract
    {
        return $this->db->transaction(function () use ($contract, $signerRole, $signerName, $by, $signer): Contract {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->getKey());

            if ($contract->status->is(ContractStatus::Signed, ContractStatus::Closed, ContractStatus::Cancelled)) {
                throw new RuntimeException('Only draft or awaiting-signature contracts can be signed.');
            }

            $contract->signatures()->create([
                'signed_by_id' => $by->id,
                'signer_role' => $signerRole,
                'signer_type' => $signer?->getMorphClass(),
                'signer_id' => $signer?->getKey(),
                'signer_name_snapshot' => $signerName,
                'method' => SignatureMethod::InPersonPaper,
                'document_hash' => $contract->document_hash,
                'signed_at' => now(),
            ]);

            $contract->status = ContractStatus::Signed;
            $contract->signed_at = now();
            $contract->save();

            return $contract;
        });
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

    /**
     * The stored document: the template's body plus the snapshot's identity, car,
     * period and pricing tables. Every snapshot value is escaped on output — it is
     * data, not markup.
     *
     * Shared by `ViewContract` (on-screen) and `renderPdf()` (the PDF), so the two
     * can never drift apart — this used to be duplicated, and the PDF's copy had
     * broken `??` operator precedence (`.` binds tighter than `??`, so every
     * fallback was dead code) that this version does not have.
     */
    public function renderDocumentHtml(Contract $contract): string
    {
        $snapshot = $contract->content_snapshot ?? [];
        $customer = $snapshot['customer'] ?? [];
        $car = $snapshot['car'] ?? [];
        $pricing = $snapshot['pricing'] ?? [];
        $period = $snapshot['period'] ?? [];
        $drivers = $snapshot['additional_drivers'] ?? [];

        $html = '';

        if (! empty($snapshot['template_body'])) {
            $html .= '<div class="contract-body">'.$snapshot['template_body'].'</div>';
        }

        $html .= '<h3>'.__('contracts.document.customer').'</h3>';
        $html .= '<p>'.e($customer['name'] ?? '')
            .' — '.e($customer['national_id'] ?? '')
            .' — '.e($customer['phone'] ?? '').'</p>';

        $html .= '<h3>'.__('contracts.document.vehicle').'</h3>';
        $html .= '<p>'.e($car['brand'] ?? '')
            .' '.e($car['model'] ?? '')
            .' ('.e($car['year'] ?? '')
            .') — '.e($car['registration_number'] ?? '').'</p>';

        $html .= '<h3>'.__('contracts.document.period').'</h3>';
        $html .= '<p>'.__('contracts.document.pickup').': '.e($period['pickup_at'] ?? '')
            .' — '.__('contracts.document.return').': '.e($period['expected_return_at'] ?? '').'</p>';

        $html .= '<h3>'.__('contracts.document.pricing').'</h3>';
        $html .= '<table><tr><th>'.__('contracts.document.item').'</th><th>'.__('contracts.document.amount').'</th></tr>';
        $html .= '<tr><td>'.__('contracts.document.rental').' ('.e($pricing['days_count'] ?? '0').' '.__('contracts.document.days').')</td><td>'.e($pricing['subtotal'] ?? '0.00').' DZD</td></tr>';
        $html .= '<tr><td>'.__('contracts.document.extras').'</td><td>'.e($pricing['extras_total'] ?? '0.00').' DZD</td></tr>';
        $html .= '<tr><td>'.__('contracts.document.discount').'</td><td>'.e($pricing['discount_amount'] ?? '0.00').' DZD</td></tr>';
        $html .= '<tr class="total"><td>'.__('contracts.document.total').'</td><td>'.e($pricing['total_amount'] ?? '0.00').' DZD</td></tr>';
        $html .= '</table>';

        if (! empty($drivers)) {
            $html .= '<h3>'.__('contracts.document.drivers').'</h3>';
            $html .= '<ul>';
            foreach ($drivers as $driver) {
                $html .= '<li>'.e($driver['full_name'] ?? '')
                    .' — '.__('contracts.document.license').': '.e($driver['driving_license_number'] ?? '').'</li>';
            }
            $html .= '</ul>';
        }

        return $this->sanitizeDocumentHtml($html);
    }

    /**
     * Strict whitelist sanitisation of the template's own HTML before it renders as
     * live markup — either on the panel or inside the PDF: only a fixed tag set
     * survives, and every attribute is dropped — no scripts, no event handlers, no
     * `style` exfiltration.
     */
    private function sanitizeDocumentHtml(string $html): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $allowed = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br', 'div', 'span', 'section', 'small', 'hr'];

        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $node) {
            if (! in_array($node->nodeName, $allowed, true)) {
                $node->parentNode?->removeChild($node);

                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                $node->removeAttribute($attribute->nodeName);
            }
        }

        $wrapper = $dom->getElementsByTagName('div')->item(0);

        if ($wrapper === null) {
            return '';
        }

        $sanitized = '';

        foreach ($wrapper->childNodes as $child) {
            $sanitized .= $dom->saveHTML($child);
        }

        return $sanitized;
    }
}
