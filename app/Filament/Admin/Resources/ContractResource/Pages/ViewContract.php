<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\Pages;

use App\Filament\Admin\Resources\ContractResource;
use App\Models\Contract;
use DOMDocument;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    public function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make(__('contracts.sections.document'))
                    ->schema([
                        TextEntry::make('document')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Contract $record): string => $this->documentHtml($record))
                            // The snapshot embeds the template's language; an Arabic
                            // contract renders right-to-left even in an LTR panel.
                            ->extraAttributes(fn (Contract $record): array => ['dir' => $record->direction()])
                            ->columnSpanFull(),
                    ]),
                Section::make(__('contracts.sections.identity'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('contract_number')->label(__('contracts.fields.contract_number')),
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('booking.reference')->label(__('Booking')),
                        TextEntry::make('customer')
                            ->label(__('Customer'))
                            ->state(fn (Contract $record): string => $record->customer?->displayName() ?? '—'),
                        TextEntry::make('car.registration_number')->label(__('Car')),
                        TextEntry::make('generated_at')->label(__('Generated'))->dateTime(),
                        TextEntry::make('signed_at')->label(__('Signed'))->dateTime()->placeholder('—'),
                        TextEntry::make('terms_version')->label(__('contracts.fields.terms_version')),
                        TextEntry::make('insurance_type')->label(__('Insurance type'))->placeholder('—'),
                        TextEntry::make('franchise_amount')->label(__('Franchise amount'))->money('DZD')->placeholder('—'),
                        TextEntry::make('document_hash')->label(__('contracts.fields.document_hash'))->fontFamily(FontFamily::Mono)->copyable(),
                        TextEntry::make('closing_notes')->label(__('Closing notes'))->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The stored document: the template's body plus the snapshot's identity, car,
     * period and pricing tables. Every snapshot value is escaped on output — it is
     * data, not markup.
     */
    private function documentHtml(Contract $contract): string
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
     * live markup on the panel: only a fixed tag set survives, and every attribute
     * is dropped — no scripts, no event handlers, no `style` exfiltration.
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
