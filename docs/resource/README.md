# Resource Task Files

One file per Filament resource. Each is a **self-contained work session**: open it, read
the docs it names, work the checklist, run its verification.

These files audit *presentation* — the actions a screen offers, and how its create, view
and edit pages should be laid out. They are deliberately separate from
[`../tasks/`](../tasks/), which tracks build phases by feature.

## How to use one

Take a single file, work its checklist, run its verification block, tick the boxes. Do not
batch several resources into one change set — the point of splitting the audit 38 ways is
that each piece stays reviewable on its own.

## Status

| # | Resource | Group | Status |
|---|---|---|---|
| [01](01-car-category.md) | **CarCategory** | Fleet | 🟡 audited — partial |
| [02](02-car.md) | **Car** | Fleet | 🔴 audited — needs work |
| [03](03-car-owner.md) | **CarOwner** | Fleet | 🔴 audited — needs work |
| [04](04-car-ownership-agreement.md) | **CarOwnershipAgreement** | Fleet | 🔴 audited — needs work |
| [05](05-car-document.md) | **CarDocument** | Fleet | 🔴 audited — needs work |
| [06](06-maintenance-schedule.md) | **MaintenanceSchedule** | Fleet | 🔴 audited — needs work |
| [07](07-maintenance-log.md) | **MaintenanceLog** | Fleet | 🔴 audited — needs work |
| [08](08-vendor.md) | **Vendor** | Fleet | 🟡 audited — partial |
| [09](09-customer.md) | **Customer** | CRM | 🔴 audited — needs work |
| [10](10-chart-of-account.md) | **ChartOfAccount** | Accounting | 🔴 audited — needs work |
| [11](11-financial-account.md) | **FinancialAccount** | Accounting | 🟡 audited — partial |
| [12](12-expense-category.md) | **ExpenseCategory** | Accounting | ✅ audited — fine |
| [13](13-transaction.md) | **Transaction** | Accounting | ✅ audited — fine |
| [14](14-cash-session.md) | **CashSession** | Accounting | ✅ audited — fine |
| [15](15-expense.md) | **Expense** | Accounting | ✅ audited — fine |
| [16](16-extra.md) | **Extra** | Bookings | ✅ audited — fine |
| [17](17-contract-template.md) | **ContractTemplate** | Bookings | ✅ audited — fine |
| [18](18-booking.md) | **Booking** | Bookings | ✅ audited — fine |
| [19](19-contract.md) | **Contract** | Bookings | ✅ audited — fine |
| [20](20-condition-report.md) | **ConditionReport** | Bookings | ✅ audited — fine |
| [21](21-car-block.md) | **CarBlock** | Bookings | ✅ audited — fine |
| [22](22-payment.md) | **Payment** | Payments | ✅ audited — fine |
| [23](23-deposit.md) | **Deposit** | Payments | ✅ audited — fine |
| [24](24-payment-schedule.md) | **PaymentSchedule** | Payments | ✅ audited — fine |
| [25](25-owner-installment.md) | **OwnerInstallment** | Payments | ✅ audited — fine |
| [26](26-fine.md) | **Fine** | Operations | 🟡 audited — partial |
| [27](27-employee.md) | **Employee** | HR | 🔴 audited — needs work |
| [28](28-employee-advance.md) | **EmployeeAdvance** | HR | ✅ done |
| [29](29-commission.md) | **Commission** | HR | ✅ done |
| [30](30-payroll-run.md) | **PayrollRun** | HR | ✅ done |
| [31](31-report.md) | **Report** | Reports | ✅ audited — fine |
| [32](32-report-definition.md) | **ReportDefinition** | Reports | ✅ done |
| [33](33-user.md) | **User** | Settings | ✅ done |
| [34](34-role.md) | **Role** | Settings | ✅ done |
| [35](35-branch.md) | **Branch** | Settings | ✅ done |
| [36](36-alert-rule.md) | **AlertRule** | Settings | ✅ done |
| [37](37-notification-log.md) | **NotificationLog** | Settings | ✅ done |
| [38](38-activity-log.md) | **ActivityLog** | Settings | 🔴 audited — needs work |

`09-customer.md` is the reference file — later ones should match its depth and structure.

## Order

Dependency order, matching [`../tasks/README.md`](../tasks/README.md): master data before
the things that reference it, ledger before bookings.

```
Fleet 01-08 ──┐
CRM   09 ─────┤
              └── Accounting 10-15   ← ledger ahead of bookings, deliberately
                   └── Bookings 16-21
                        └── Payments 22-25
                             ├── Operations 26
                             ├── HR 27-30
                             └── Reports 31-32
Settings 33-38  ← last: it grants access, so it is audited once the rest is understood
```

## What the audit adds up to

All 38 resources audited.

| | Count |
|---|---|---|
| 🔴 needs work | 21 |
| 🟡 partial | 11 |
| ✅ fine | 8 — [31 Report](31-report.md), [12 ExpenseCategory](12-expense-category.md), [13 Transaction](13-transaction.md), [15 Expense](15-expense.md), [16 Extra](16-extra.md), [22 Payment](22-payment.md), [23 Deposit](23-deposit.md), [35 Branch](35-branch.md) |

Four themes account for most of it, and each is one sweep rather than 38 fixes:

1. **Authorization is largely absent.** 25 of the 38 files record a missing `canAccess()` (FinancialAccount now has one).
   The worst cases are not the money screens but HR: any staff role can read every colleague's
   salary ([27](27-employee.md)), grant themselves a salary advance ([28](28-employee-advance.md)),
   and approve and pay payroll ([30](30-payroll-run.md)).
2. **`DeleteBulkAction` is nearly universal** — flagged in 27 of 38 files (FinancialAccount now uses `->bulkActions([])`), including on posted money
   records, the chart of accounts, and condition reports that justify charges already in the ledger.
3. **Records stay editable after they post.** Expense, Fine, OwnerInstallment and
   Booking all keep a fully open edit form after posting to an append-only ledger, so a figure can
   change while the row that recorded it cannot. Payment and Deposit now freeze their money fields
   once posted ([22](22-payment.md), [23](23-deposit.md)) — the pattern each of the rest should be
   swept to.
4. **Several services exist but are called from nowhere.** The Fleet audit found
   `FleetStatusService`, `MaintenanceSchedulerService::recomputeSchedule()` and
   `OwnerStatementService::generateMonthlyInstallments()` each referenced only by a test or not at
   all, while the resources write the same fields directly. With findings 9 and 12
   (`Money::allocate()` and `MoneyCast` unused), the pattern is consistent: the service layer was
   built to spec and the UI was wired around it rather than through it.

### The four most serious findings

None is a presentation issue. All four were verified against the running application, not inferred.
**Three of the four are now fixed** — see the ✅ markers below and
`tests/Feature/PrivilegeEscalationTest.php`.

- **✅ FIXED — a manager could make themselves `super_admin`.** `UserResource::canAccess()` gates on
  `branches.view_all` (`UserResource.php:43`), which `RolePermissionSeeder` grants to Manager, and
  the roles field is an unfiltered `Select::make('roles')->relationship('roles', 'name')` — so
  `super_admin` is in the list. Verified live: for `manager@mcars.dz`, `canAccess()` returns
  **true** for both UserResource and RoleResource. See [33](33-user.md).
- **✅ FIXED — password hashes were in the audit log, and the UI displayed them.** `logAll()` serialises the
  whole model, so `activity_log.attribute_changes` contains bcrypt hashes — the `#[Hidden]`
  attribute governs Eloquent serialisation, not Spatie's activity logger. Verified: 5 rows contain
  a `$2y$12$…` hash, including `manager@mcars.dz`'s. See [38](38-activity-log.md).
- **✅ FIXED — the ledger's only correction path was unreachable.** `reverse_transaction` is now
  seeded to super_admin and accountant. See finding 8 and [13](13-transaction.md).
- **⬜ OPEN — the Phase 0 money-safety layer was built and never adopted** — `MoneyCast` in zero models,
  `Money::allocate()` never called, float arithmetic on money in a service. See finding 12.

## Findings that span the whole panel

Verified across all 38 resources on 2026-07-29. Fixing these once is worth more than
fixing them 38 times, so they are recorded here rather than repeated in every file.

1. **Only 8 resources have a view page** — ActivityLog, Car, Customer, Expense,
   FinancialAccount, NotificationLog, Report, Transaction. The other 30 are index/create/edit. Most do not
   need one; the ones that do are the records with history hanging off them (Booking is
   the glaring omission — a booking is the hub of the system and has no view page).
2. **Only 11 of 38 declare `canAccess()`.** Every other resource is reachable by any
   staff role, including Settings resources that grant access. Money is gated on the
   permission `reports.view_financials`, cross-branch visibility on `branches.view_all` —
   never a role list.
3. **31 resources still use the deprecated Filament 5 alias `->actions([...])`** instead
   of `->recordActions([...])`. Mechanical, but worth one sweep.
4. **Relation managers exist on only 3 resources** — Car (3), Customer (1) and FinancialAccount (2). This is
   the largest single gap in the panel: the data is all related, and almost none of it is
   reachable from the record it belongs to. Where a related table is read-only history it
   belongs on the **view** page; where the office edits it in place, on **edit**.
5. **`app/Actions/` does not exist.** CLAUDE.md's layering table names an Action layer
   ("one use case, `execute()`") that was never materialised. Recommendations to move
   logic out of a Filament action should target `app/Services/` until that changes.
6. **All five enum-vs-string PHPStan warnings are false positives — do not "fix" the code.**
   Each was investigated against the model's `casts()` and the runtime value, not just read off
   the PHPStan output:
   `CashSessionResource.php:104` and `EditCashSession.php:47` (`CashSession::casts()` →
   `'status' => CashSessionStatus::class`; a live row returns a real enum instance),
   `ContractResource.php:82` and `:89` (`Contract.php:52` casts `status`; `content_snapshot` is
   nullable JSON, not a non-null string), and
   `CarOwnershipAgreementResource.php:122` and `:140` (`CarOwnershipAgreement.php:41` casts
   `status`; verified that `$agreement->status === AgreementStatus::Active` returns **true** at
   runtime), and `MaintenanceLogResource.php:128` and `:169`.
   In every case larastan reads the column type from the `varchar` schema rather than from
   `casts()`. The remedy is a `@property` docblock on each model — the same fix already applied
   to `User::$locale`. **Changing the comparisons would break working code**, and the actions
   guarded by them would stop appearing.
   Worth taking seriously anyway, because a closely related bug once made the bookings list —
   the busiest screen in the system — return a 500, when Filament resolved an enum from the
   container because a closure type-hinted it. See the docblock of
   `tests/Feature/ResourcePagesRenderTest.php`, which exists because of it.
7. **Empty `->filters([])`** appears on tables that grow without bound — including
   `transactions`, which becomes the largest table in the database and currently cannot be
   narrowed at all. (✅ `transactions` since fixed — see [13](13-transaction.md).)
8. **One permission is gated on but never seeded: `reverse_transaction`.** It guards the
   only correction path for the append-only ledger, and no user — including super_admin —
   can see the action. See [13](13-transaction.md) gap 1.
   The whole set was checked, so this is bounded: `app/` gates on exactly five permissions
   (`alerts.manage`, `alerts.view_logs`, `branches.view_all`, `reports.view_financials`,
   `reverse_transaction`) and `RolePermissionSeeder` creates the first four.
   `reverse_transaction` is the only orphan.

9. **`Money::allocate()` is never called anywhere in `app/`.** Verified: the only occurrences
   are its definition at `app/Support/Money.php:122` and an unrelated string in
   `SequenceGenerator`. It is the Phase 0 primitive built specifically to split an amount
   without losing a centime, and the one place that needs it — generating instalments —
   does not exist, so amounts are typed in by hand. See [24](24-payment-schedule.md) gap 1.

   **Resolved by 24** — `PaymentScheduleService::generate()` splits with `Money::allocate()`;
   gap 1 no longer applies.
10. **Money records stay editable after they post to the ledger.** Payment, Expense,
    Deposit and OwnerInstallment all keep a fully open edit form after posting, so a figure can be
    changed while the append-only rows that recorded it cannot. This is one pattern, not four
    bugs — worth a single sweep. Payment and Deposit are now frozen
    ([15](15-expense.md), [22](22-payment.md), [23](23-deposit.md), [25](25-owner-installment.md)).
11. **`DeleteBulkAction` appears on almost every resource**, including posted money records and
   the chart of accounts. Soft deletes preserve the row, but the ledger keeps referencing
   records the UI has hidden, so figures reconcile to something nobody can open.

12. **🔴 `MoneyCast` is used by zero models, and money arithmetic happens on floats.**
   The most serious thing this audit found, and it is not a presentation issue.
   CLAUDE.md states the convention without qualification: *"Money: `decimal(18,2)`, cast
   through `MoneyCast`. **Never `float`.**"* — and lists both `App\Support\Money` (integer
   minor units) and `App\Support\Casts\MoneyCast` as Phase 0 foundation primitives.
   Verified: `MoneyCast` appears in **no model**. All 18 money-bearing models
   (Booking, Payment, Deposit, Expense, Transaction, Fine, Contract, Car, CashSession,
   MaintenanceLog, OwnerInstallment, Extra, BookingExtra, FinancialAccount, CarDocument,
   CarOwnershipAgreement, CashRegisterEntry, NotificationLog) cast money as `'decimal:2'`,
   which returns a **string**.
   The consequence is not theoretical. `app/Services/Booking/BookingAvailabilityService.php:129`
   reprices a rental as
   `$booking->total_amount = $booking->daily_rate * $booking->days_count + $booking->extras_total - $booking->discount_amount;`
   — four `decimal:2` strings coerced to float, multiplied and subtracted, then written back
   to a money column. Together with finding 9 (`Money::allocate()` never called), the entire
   money-safety layer was built in Phase 0 and never adopted.
   This belongs in its own task outside this directory; it is recorded here because the audit
   is what surfaced it and several resource files depend on the outcome.

13. **No authorization layer exists at all — this is the root cause of most of finding 2.**
   `app/Policies/` **does not exist**, and Shield's `shield:generate` was never run, so no
   per-resource permissions were ever created. Filament falls back to allowing access when no
   policy and no `canAccess()` is present, which is why 26 of the audited resources are open to
   every staff role. Adding `canAccess()` resource by resource treats the symptom; the decision to
   make is whether this panel gets policies, Shield-generated permissions, or explicit
   `canAccess()` everywhere — and it should be made once, before 26 separate fixes are written.
   Every new permission proposed in these files (`users.manage`, `roles.manage`, `audit.view`, a
   payroll-confidentiality permission) must also be added to `RolePermissionSeeder`: with
   `define_via_gate => false` and no `Gate::before`, an unseeded permission is invisible even to
   super_admin — see finding 8 for what that costs.

## Rules any recommendation here obeys

- A resource defines a form and a table. It never writes to `transactions`, never sums
  amounts, never decides whether a car is available.
- Every ledger aggregation goes through `ReportService`. A figure that does not exist
  there yet is a task to add a method, not to sum it in a resource.
- The ledger is append-only (ADR-003). No resource offers edit or delete on
  `transactions`; corrections are reversal rows.
- A security deposit is a liability, never revenue.
- No stored balances. A "paid to date" column is derived or it does not exist.
- The panel loads Filament's compiled stylesheet with **no custom theme**, so bespoke
  Tailwind utility classes have no rules behind them at runtime. Filament blade
  components only.
- Labels translate globally through `lang/fr.json` / `lang/ar.json`; a new label is a new
  dictionary entry, not a per-resource key.
