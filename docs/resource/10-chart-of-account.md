# 10 — ChartOfAccount (Accounting)

**Model:** `App\Models\ChartOfAccount` · **Slug:** `/admin/chart-of-accounts` · **Status:** 🟢 done

Closes **REQ-08**. Read [`../05-accounting-model.md`](../05-accounting-model.md) — this
table *is* the posting matrix's vocabulary.

## What it is for

The account codes every ledger row points at. An accountant opens it to see the tree and to
add a new expense account; nobody else should open it. It is master data with unusually
sharp teeth: the posting matrix names specific codes (1110 customer receivables, 2100
deposits held, 2200 owner payable, 2600 inter-branch clearing, 5060 taxes &
registration), and `ReportService` resolves several of them by code at runtime.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | code, name, type, parent, cash-equivalent + postable icons; `->filters([])` empty |
| create | ✅ | includes editable `is_system`, `is_postable`, `is_cash_equivalent` toggles |
| view | ✅ | added — account fields, flags, and the two relations below |
| edit | ✅ | same form; nothing frozen |
| row actions | ✅ | Edit, Delete — via deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** in a bulk group |
| relation managers | ✅ | `children` and `transactions` (both legs, read-only), on the view page |
| `canAccess()` | ✅ | present (`ChartOfAccountResource.php:40`) |

## Should be

### Index
Show the tree, not a flat list — accounts are hierarchical via `parent_id` and reading them
in code order without indentation makes the structure invisible. Either group by `type`
(asset / liability / revenue / expense) or sort by `code` and indent children.

Add filters: `type`, `is_active`, `is_postable`, and a ternary for `is_system` so an
accountant can see at a glance which accounts are load-bearing.

### Create
Fine, with one change: `is_system` must not be a user-settable toggle (below).

### View
**Added.** An account is six fields, but the `transactions` relation on it is a real
convenience — "show me everything posted to 5060 this year" — and is exactly what the view
page now offers, alongside the `children` sub-account list.

### Edit
`code` must freeze once the account has postings — `ReportService` and the seeded posting
matrix resolve accounts **by code** (`ChartOfAccount::where('code', '2600')`), so renaming
a code silently redirects or breaks those lookups. `type` and `normal_balance` must freeze
too: flipping the normal balance of an account that already has rows inverts the meaning of
every one of them. `name`, `name_ar`, `name_fr` and `description` stay editable.

### Relations
Two candidates, both read-only:

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `children` | view | yes | — | code, name, type |
| `transactions` (both legs) | **view** | **yes, strictly** | `reports.view_financials` | reference, date, amount, other leg |

A postings table must match either leg (debit **or** credit), which is a query, not a plain
`hasMany`. Strictly read-only — no create, edit, delete or bulk actions (ADR-003).

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `EditAction` | row | always | `canAccess` only | — | should refuse `is_system` accounts |
| `DeleteAction` | row | always | `canAccess` only | — | must refuse accounts with postings |
| `DeleteBulkAction` | toolbar | always | `canAccess` only | — | **remove** — gap 1 |

## Gaps and risks

1. **🔴 `DeleteBulkAction` on the chart of accounts.** Deleting account 2100 breaks deposit
   posting; deleting 2600 breaks inter-branch transfers and the company-wide totals that
   exclude it. Soft deletes keep the row, but the code lookups
   (`where('code', …)->value('id')`) will return null and postings will fail or silently
   mis-post. Remove the bulk delete, and make single delete refuse any account that has
   postings or `is_system = true`.
2. **🔴 `is_system` is a user-editable toggle** (`ChartOfAccountResource.php:73`). The flag
   exists to mark accounts the posting matrix depends on; letting a user clear it removes
   the only marker protecting them. It should be display-only in the form (`disabled()`),
   set by the seeder, and the row's delete/edit guards should read it.
3. **🟡 `is_postable` is freely editable.** Marking a parent account postable, or a leaf
   non-postable, will make `AccountingService` reject drafts at runtime. Worth a warning or
   a guard.
4. **🟡 No filters** on a table that is read by structure — see Index.
5. **🟡 Flat list hides the tree.**
6. **🟡 Deprecated `->actions([...])`.**
7. **🔵 `name_ar` / `name_fr` exist as columns** but the panel now translates labels through
   `lang/{fr,ar}.json`. Confirm which mechanism wins for account *names* on reports and PDFs,
   so the two do not disagree.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/SchemaConventionsTest.php
docker compose exec app php artisan db:seed --class=ChartOfAccountSeeder
```

By hand: attempt to delete account 2100 and confirm it is refused; confirm `is_system` cannot
be cleared through the form. Re-running `ChartOfAccountSeeder` must remain idempotent.
