<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\AlertRule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * At most one *active* rule per (type, branch) — and at most one active global
 * rule per type.
 *
 * Two partial unique indexes already enforce this at the database level
 * (`alert_rules_type_branch_active_unique`, `alert_rules_type_global_active_unique`),
 * but they surface as a raw QueryException when the form collides with them —
 * which is exactly what a manager does when the seeder already created a global
 * rule for every type. This rule turns the database constraint into a field
 * error. Soft-deleted and deactivated rules are excluded, mirroring the index
 * predicates: a deactivated rule does not block re-creating the rule it replaced.
 */
final class UniqueActiveAlertRule implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreId = null,
        private readonly string|int|null $branchId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $query = AlertRule::query()
            ->active()
            ->where('type', $value)
            ->where('branch_id', $this->branchId);

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail(__('notifications.resources.alert_rule.validation.duplicate'));
        }
    }
}
