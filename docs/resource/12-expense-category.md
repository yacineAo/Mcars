# 12 — ExpenseCategory (Accounting)

**Model:** `App\Models\ExpenseCategory` · **Slug:** `/admin/expense-categories` · **Status:** 🟡 partial

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
| index | ✅ | `->filters([])` empty |
| create | ✅ | name, name_ar, name_fr, slug, parent, ledger account, is_car_related, is_recurring_default, sort_order, is_active |
| view | ❌ | not needed — see below |
| edit | ✅ | nothing frozen |
| row actions | ✅ | Edit, Delete — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | self-referencing `parent_id` tree, not surfaced |
| `canAccess()` | ❌ | absent |

## Should be

### Index
Sort by `sort_order` then name — the column exists and is currently not driving anything
visible. Show the mapped ledger account, `is_car_related`, and the child/parent relationship.
Add `is_active` and `is_car_related` filters.

`is_car_related` deserves prominence: it is what decides whether an expense can be attributed
to a car, and therefore whether it reaches per-car profitability. Getting it wrong on a
category silently removes a cost from every car's P&L.

### Create
Fine. `slug` should be derived from `name` rather than typed, and `ledger_account_id` should
be limited to `is_postable` expense-type accounts.

### View
**Not needed.** Ten fields, no history of its own. The expense list filtered by category
already answers "what is in this bucket", and the expense breakdown report answers it with
totals.

### Edit
Freeze `slug` once referenced, and freeze `ledger_account_id` once expenses exist in the
category — changing it does not re-post the expenses already booked, so the category's
historical rows and its future ones would sit in different ledger accounts while appearing
identical in the report.

### Relations
None needed. `children` could be shown on the index as a tree; a relation manager is
overkill for a two-level list.

Explicitly **not** here: the category's expenses. That is a filtered view of
[`15-expense.md`](15-expense.md), and its totals belong in the expense breakdown report —
not a table on master data.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `EditAction` | row | always | **nothing** | — | freeze slug + ledger account once used |
| `DeleteAction` | row | always | **nothing** | — | refuse where expenses exist; prefer `is_active` |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 1 |

## Gaps and risks

1. **🔴 `DeleteBulkAction`.** Categories are referenced by expenses and by `transactions.expense_category_id`.
   Deleting them — even softly — leaves ledger rows grouped under a category that no longer
   resolves, so `expenseBreakdown()` loses or mislabels a row. Remove the bulk action; make
   single delete refuse a category with expenses and offer deactivation instead. `is_active`
   already exists for exactly that purpose.
2. **🟡 No `canAccess()`.** Editing the ledger-account mapping changes where money posts, so
   this is not a screen for every staff role.
3. **🟡 `ledger_account_id` unrestricted** — see Create.
4. **🟡 `sort_order` not applied** to the default sort.
5. **🟡 No filters.**
6. **🟡 Deprecated `->actions([...])`.**
7. **🔵 `name_ar` / `name_fr` versus the new `lang/{fr,ar}.json` dictionary.** Category names
   are data, not UI labels, so the columns are the right mechanism here — worth a one-line
   note in the code saying so, since the panel now translates labels the other way and the
   two conventions sit side by side.

## Checklist

- [ ] Remove `DeleteBulkAction`; refuse deleting a category with expenses, prefer `is_active`
- [ ] Add `canAccess()`
- [ ] Derive `slug` from `name`
- [ ] Restrict `ledger_account_id` to postable expense accounts
- [ ] `defaultSort` on `sort_order`
- [ ] Add `is_active` / `is_car_related` filters; surface `is_car_related` clearly
- [ ] Freeze `slug` and `ledger_account_id` once expenses reference the category
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app php artisan db:seed --class=ExpenseCategorySeeder
```

By hand: confirm a category with expenses cannot be deleted. Then check the expense breakdown
report at `/admin/reports` still lists every category with a non-zero total, and that a
category marked `is_car_related` actually attributes to a car in fleet profitability.
