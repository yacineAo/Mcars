<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The token vocabulary a contract template body can use, and the sample data the
 * preview page substitutes for them.
 *
 * Tokens are the flat `{{name}}` form the seeded bodies already use.
 *
 * **Nothing substitutes them at render time yet.** `ContractService::renderHtml()`
 * emits `template_body` verbatim, so a token authored today reaches the signed PDF
 * as literal `{{customer_name}}` — that gap belongs to `docs/resource/19-contract.md`
 * and is why `warning()` exists and is shown wherever this vocabulary is advertised.
 *
 * Note the shapes do not match either: `ContractService::buildSnapshot()` emits a
 * nested array (`customer.name`, `pricing.total_amount`) while these tokens are flat.
 * Whoever implements substitution owns mapping one onto the other, and must treat
 * this list as the contract with template authors — renaming a token silently breaks
 * every template already written against it.
 */
class ContractTemplatePreview
{
    /** @return array<string, string> token => translation key for its description */
    public static function tokens(): array
    {
        return [
            '{{customer_name}}' => 'Customer name',
            '{{customer_phone}}' => 'Customer phone',
            '{{customer_city}}' => 'Customer city',
            '{{car_description}}' => 'Car description',
            '{{car_registration_number}}' => 'Registration number',
            '{{pickup_at}}' => 'Pickup at',
            '{{expected_return_at}}' => 'Expected return at',
            '{{daily_rate}}' => 'Daily rate',
            '{{days_count}}' => 'Days count',
            '{{total_amount}}' => 'Total amount',
            '{{security_deposit_amount}}' => 'Security deposit amount',
            '{{booking_reference}}' => 'Booking reference',
        ];
    }

    /** @return array<string, string> token => sample value for the preview */
    public static function sampleData(): array
    {
        return [
            '{{customer_name}}' => 'Ahmed Benali',
            '{{customer_phone}}' => '0550 12 34 56',
            '{{customer_city}}' => 'Alger',
            '{{car_description}}' => 'Renault Clio 4 — 2022',
            '{{car_registration_number}}' => '16-123-456',
            '{{pickup_at}}' => '2026-08-01 09:00',
            '{{expected_return_at}}' => '2026-08-10 09:00',
            '{{daily_rate}}' => '6,500 DZD',
            '{{days_count}}' => '9',
            '{{total_amount}}' => '58,500 DZD',
            '{{security_deposit_amount}}' => '40,000 DZD',
            '{{booking_reference}}' => 'BK-2026-0042',
        ];
    }

    /** Substitute sample data into a template body for the preview. */
    public static function render(string $body): string
    {
        return strtr($body, self::sampleData());
    }

    /**
     * The caveat that must accompany the token list anywhere it is shown.
     *
     * An author who sees a resolved preview reasonably concludes the tokens work.
     * They do not yet, and finding that out on a customer's signed contract is the
     * failure the preview was added to prevent.
     */
    public static function warning(): string
    {
        return __('Placeholders are not substituted yet — they appear literally on a rendered contract. Write terms that read correctly without them until contract rendering is completed.');
    }

    /**
     * The available tokens as a list, for form helper text.
     *
     * A newline-joined string collapses to one run-on line in HTML, so this returns
     * real markup. Labels are escaped: they come from translation files.
     */
    public static function reference(): HtmlString
    {
        $items = '';

        foreach (self::tokens() as $token => $label) {
            $items .= '<li><code>'.e($token).'</code> — '.e(__($label)).'</li>';
        }

        return new HtmlString(
            '<span class="font-medium">'.e(self::warning()).'</span>'.
            '<div class="mt-1">'.e(__('Available placeholders')).':</div>'.
            '<ul class="list-disc list-inside">'.$items.'</ul>',
        );
    }
}
