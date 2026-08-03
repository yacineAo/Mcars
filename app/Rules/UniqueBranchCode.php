<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Branch;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A branch code is the address of a document-numbering universe:
 * "CTR-MAIN-2026-000123" reads via branches.code. Two live branches sharing a
 * code would hand out indistinguishable document numbers, so uniqueness is
 * enforced here even though the column also has a unique index — the index
 * gives no feedback to the operator, this rule does.
 *
 * The comparison is case-insensitive but the form upper-cases input anyway;
 * the global soft-delete scope already excludes deleted branches: their
 * documents stopped being numbered the day they were deleted, so nothing
 * collides with them.
 */
final class UniqueBranchCode implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $query = Branch::query()->whereRaw('upper(code) = upper(?)', [$value]);

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail(__('branches.validation.code_unique'));
        }
    }
}
