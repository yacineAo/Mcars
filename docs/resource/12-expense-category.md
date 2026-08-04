# 12 — ExpenseCategory (Accounting)

**Model:** `App\Models\ExpenseCategory` · **Slug:** `/admin/expense-categories` · **Status:** ✅ done

Supports **REQ-10** (expense reporting by category). See
[`../05-accounting-model.md`](../05-accounting-model.md).

## What it is for

The buckets expenses fall into — fuel, maintenance, salaries, office rent — each mapped to a
ledger account via `ledger_account_id`. Small master-data screen, set up once. It matters
more than its size suggests because `ReportService::expenseBreakdown()` groups by it, so the
category list *is* the expense report's row labels.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | sorted by `sort_order` then name; filters: is_active, is_car_related, ledger account |
| create | ✅ | slug auto-derived from name via `live(onBlur: true)`; name_ar, name_fr, parent, ledger account (restricted to postable expense-type accounts), is_car_related, is_recurring_default, sort_order, is_active |
| view | ✅ | added — name/slug/parent/ledger account/flags, no relation manager |
| edit | ✅ | `ledger_account_id` frozen once expenses exist in the category; slug hidden and derived on create only |
| row actions | ✅ | Edit — `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction` only; bulk actions removed |
| relation managers | ❌ | none — not needed |
| `canAccess()` | ✅ | gates on `reports.view_financials` |

## Gaps and risks (resolved)

| # | Gap | Resolution |
|---|---|---|
| 1 | `DeleteBulkAction` | Removed `->bulkActions([])`. Single delete refused via `canDelete()` if `hasExpenses()`. Deactivation via `is_active` is the alternative. |
| 2 | No `canAccess()` | Added — gates on `reports.view_financials`. |
| 3 | `ledger_account_id` unrestricted | Now filtered to `is_postable` expense-type accounts via the relationship callback. Also frozen on edit once expenses exist. |
| 4 | `sort_order` not applied | `orderBy('sort_order')->orderBy('name')` via `modifyQueryUsing`, which also eager-loads `parent`. |
| 5 | No filters | Added: `is_active` (defaults to true), `is_car_related`, and `ledger_account_id`. |
| 6 | Deprecated `->actions([...])` | Changed to `->recordActions([...])`. |
| 7 | `name_ar` / `name_fr` vs JSON dictionary | Columns are correct for data (not UI labels). Slug auto-derived from `name`. |

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app php artisan db:seed --class=ExpenseCategorySeeder
```

By hand: confirm a category with expenses cannot be deleted. Then check the expense breakdown
report at `/admin/reports` still lists every category with a non-zero total, and that a
category marked `is_car_related` actually attributes to a car in fleet profitability.
