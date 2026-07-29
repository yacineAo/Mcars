<?php

declare(strict_types=1);

namespace App\Support;

final class Label
{
    /**
     * Translate a UI label, guaranteeing a string back.
     *
     * `__()` returns an **array** when the key collides with a translation *filename*:
     * `__('bookings')` has no dot, so Laravel falls through to group lookup and loads
     * the whole of `lang/{locale}/bookings.php`. Four model labels collide today —
     * bookings, payments, deposits, fines — and any lang file added later adds more.
     *
     * It only bites in locales with no JSON dictionary (English here), because a JSON
     * hit is checked first and short-circuits the group lookup. That makes it exactly
     * the kind of bug that passes in French and 500s in English, so the guard belongs
     * here rather than at each call site.
     */
    public static function translate(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
