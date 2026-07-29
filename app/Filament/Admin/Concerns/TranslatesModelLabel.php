<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Support\Label;

use function Filament\Support\get_model_label;

/**
 * Routes a resource's model label through the shared translation dictionary.
 *
 * Filament derives the label from the model class name (`CarOwner` → "car owner") and
 * does *not* translate it — unlike field and column labels, which the panel opts into
 * translating globally. That leaves the sidebar item, the page titles and every
 * "Create …" button in English regardless of the user's language.
 *
 * The key is the derived English label, so these share the one dictionary in
 * lang/{ar,fr}.json with every other label. A resource that declares its own
 * `getModelLabel()` keeps it: a class method wins over a trait method.
 */
trait TranslatesModelLabel
{
    public static function getModelLabel(): string
    {
        return Label::translate(get_model_label(static::getModel()));
    }

    public static function getPluralModelLabel(): string
    {
        // Pluralise the English key, then translate — Arabic and French plurals are not
        // formed by appending 's', so the plural is its own dictionary entry.
        //
        // Label::translate rather than __(): these keys are bare lowercase words, and
        // "bookings", "payments", "deposits" and "fines" each name a real file under
        // lang/en/, which __() would return as an array.
        return Label::translate(str(get_model_label(static::getModel()))->plural()->toString());
    }
}
