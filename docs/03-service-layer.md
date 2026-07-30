# 03 — Service Layer

## Calling convention

The rule that keeps this codebase from becoming fat models and 800-line Filament resources:

| Layer | Owns | Never does |
|---|---|---|
| **Model** | Relationships, casts, scopes, accessors over its **own** columns | Money math, cross-aggregate writes, notifications |
| **Action** | One use case, one public `execute()`. `ConfirmBooking`, `CloseContract`, `RefundDeposit` | Orchestrate other use cases |
| **Service** | Orchestration across aggregates; the transaction boundary | Render UI, know about Filament |
| **DTO** | Typed input (`readonly` classes). `TransactionDraft`, `BookingQuote`, `PaymentIntent` | Contain logic |
| **Event / Listener** | Side effects — notifications, cache flush, activity log | Business decisions |
| **Filament Resource** | Form/table definition; calls one Action or Service per button | Contain business rules |

Concretely: a Filament page never calls `Transaction::create()`, never sums amounts, and never decides
whether a car is available. It calls a service and renders the result.

---

## 1. `AccountingService` — **the only writer to the ledger**

Posts every financial event to `transactions` as a balanced double-entry row, and is the single place
where that write is permitted. Also handles corrections by posting reversals, never by editing history.

```
post(TransactionDraft $draft): Transaction
postMany(TransactionDraft ...$drafts): Collection   // atomic — all or nothing
reverse(Transaction $t, string $reason, ?User $by): Transaction
balanceOf(ChartOfAccount $a, ?Period $p, array $dimensions = []): Money
```

Responsibilities on every `post()`: validate both accounts exist and are `is_postable`; reject
`amount <= 0`; allocate the `TRX-…` reference from `sequences` under row lock; stamp `created_by_id`,
`branch_id`, `posted_at` and the open `cash_session_id`; wrap in a DB transaction; dispatch
`TransactionPosted`.

It is deliberately **thin**. It does not know what a booking is. The knowledge of *which accounts* a
business event hits lives in **Posters**, one per document type, each producing `TransactionDraft`s
from the posting matrix in [`05-accounting-model.md`](05-accounting-model.md):

`BookingPoster` · `PaymentPoster` · `ExpensePoster` · `DepositPoster` · `FinePoster` ·
`OwnerInstallmentPoster` · `PayrollPoster` · `CashSessionPoster`

*(`MaintenancePoster` was never built — completed maintenance posts through `ExpensePoster`.)*

Every poster stamps `source_type` / `source_id` on its drafts, which is how a document knows whether
it has already been posted and what makes the Filament actions safely idempotent.

Adding a new financial event = adding a Poster and a matrix row. `AccountingService` never changes.

> **Enforcement.** `Transaction` model guards `creating` against a call-context flag that only
> `AccountingService` sets, and blocks `updating`/`deleting` outright; a Postgres trigger blocks
> `UPDATE`/`DELETE` at the database level too. Belt and braces, because this invariant is the system.

---

## 2. `CashRegisterService` — REQ-09

Computes the real-time balance of any cash-equivalent account directly from the ledger, and manages
register shifts: open with a float, close with a physical count, reconcile the variance.

```
balance(FinancialAccount $a, ?Carbon $asOf): Money       // Σ debits − Σ credits, from transactions
balanceAll(?Branch $b): Collection
balancesBatch(Collection $accounts): array               // [accountId => balance_string], N+1-safe
openSession(FinancialAccount $a, Money $float, User $by): CashSession
closeSession(CashSession $s, Money $counted, User $by): CashSessionSummary
entries(FinancialAccount $a, Period $p): LazyCollection  // the cash_register_entries view
transfer(FinancialAccount $from, FinancialAccount $to, Money $amount, User $by): Transaction
```

`closeSession()` computes expected from the ledger, compares to the physical count, and — when they
differ — **posts the variance as a real transaction** (cash over/short) so the ledger continues to
reconcile to the money in the drawer. A variance is never silently absorbed; it raises an alert.

---

## 3. `BookingAvailabilityService` — REQ-05, ADV-01

Answers "is this car free for this period" and produces the calendar feed. Exists because
double-booking prevention needs a single owner; scattering overlap checks across form validation,
the calendar and the API is how the third check gets forgotten.

```
isAvailable(Car $c, CarbonPeriod $p, ?Booking $excluding): bool
availableCars(CarbonPeriod $p, ?CarCategory $cat, ?Branch $b): Collection
conflictsFor(Car $c, CarbonPeriod $p): Collection        // bookings + car_blocks
calendarFeed(CarbonPeriod $p, array $filters): array     // resource-timeline payload
reserve(Booking $b): void                                // inside a DB transaction
extend(Booking $b, Carbon $newReturn): void
```

The pre-check is a **courtesy** for good UX. The guarantee is the Postgres `EXCLUDE` constraint; this
service catches the `23P01` violation and converts it to a friendly validation error. Design note: a
`SELECT`-then-`INSERT` check alone is a race, and under two receptionists on two machines it *will*
double-book eventually.

---

## 4. `PricingService`

Turns a car + period + extras + discount into a priced quote: applies the daily/weekly/monthly tier,
prices extras by their unit, applies the discount, and returns the deposit required. No tax is charged.

```
quote(Car $c, CarbonPeriod $p, array $extras, ?Money $discount): BookingQuote
closeout(Booking $b, ConditionReport $in): CloseoutQuote  // extra km, fuel, late hours, cleaning
```

Bookings must not compute their own totals — otherwise the wizard, the API and the contract PDF each
grow a slightly different version of the rate ladder. `BookingQuote` is snapshotted onto the booking
at confirmation so later price-list changes cannot rewrite history. `closeout()` produces the extra
charges that `DepositService` may deduct.

---

## 5. `ContractService` — REQ-06, ADV-02

Generates a contract from a template, freezes its content, renders the PDF, and drives it through the
signature and delivery lifecycle.

```
generate(Booking $b, ?ContractTemplate $t, string $locale): Contract
renderPdf(Contract $c): string                  // path on the private disk
send(Contract $c, array $channels, ?string $to): void
close(Contract $c, ConditionReport $in, User $by): void
amend(Contract $c, array $changes): Contract    // new version, parent_contract_id chain
```

`generate()` writes `content_snapshot` — the fully-resolved terms, parties, vehicle and prices as at
that moment. The PDF is regenerable from the snapshot forever, so a contract signed in 2026 still
renders identically in 2029 after the template and price list have changed. Locale drives Arabic RTL,
French or English output. `send()` delegates to `MessagingService` and logs every attempt.

## 6. `SignatureService` — REQ-06

Handles e-signature capture: issues and verifies the OTP, stores the drawn signature, and hashes the
document at the moment of signing.

```
requestOtp(Contract $c, string $signerRole, string $phone): void
verifyOtp(Contract $c, string $signerRole, string $code): ContractSignature
captureDrawn(Contract $c, string $signerRole, string $pngDataUri): ContractSignature
finalise(Contract $c): void      // all parties signed → status = signed, hash stored
```

Each signature records the SHA-256 of the PDF as it stood when that party signed, plus IP, user agent
and timestamp. Without the hash, "this is not the document I signed" has no technical answer.

---

## 7. `DepositService` — ADV-07

Manages the security deposit lifecycle — hold, deduct, refund, forfeit — with the correct liability
postings at each step.

```
hold(Booking $b, Money $amount, PaymentMethod $m, FinancialAccount $a): Deposit
deduct(Deposit $d, DeductionReason $r, Money $amount, string $note, ?ConditionReport $ev): DepositDeduction
refund(Deposit $d, FinancialAccount $a, User $by): Payment
forfeit(Deposit $d, string $reason): void
```

Enforces the rule the whole design turns on: **a deposit is a liability, never revenue.** Holding it
credits *Security Deposits Held*; only a deduction converts part of it to revenue; a refund returns
cash and clears the liability. Total deductions can never exceed the deposit — the excess becomes a
receivable instead.

---

## 8. `OwnerStatementService` — REQ-03

Generates instalments from ownership agreements, and builds the monthly statement **staff** produce for
an owner. There is no owner portal (ADR-007).

```
generateInstallments(Carbon $month): Collection          // scheduled monthly
statement(CarOwner $o, Period $p): OwnerStatement
balance(CarOwner $o): Money                              // from the Payable–Owners account
recordPayment(OwnerInstallment $i, Money $amt, PaymentMethod $m, FinancialAccount $a): Payment
```

Statement contents differ by agreement model — full gross revenue and share calculation for
`revenue_share`, owner-scoped activity plus agreed rent for `fixed_monthly`. See the disclosure note
in [`02-filament-panels.md`](02-filament-panels.md).

---

## 9. `FineLiabilityService` — REQ-14

Matches a fine's `violation_at` timestamp against the contracts that were active for that car at that
instant, and **proposes** who is liable.

```
suggestLiability(Fine $f): LiabilitySuggestion   // customer + contract, or company, + confidence
assign(Fine $f, FineLiability $l, User $by, ?string $note): void
recharge(Fine $f): Transaction                   // customer-liable → receivable
recoverFromDeposit(Fine $f, Deposit $d): DepositDeduction
```

It suggests; a human confirms. Automatic assignment of a legal liability without review is not
acceptable, and the offence time is often ambiguous around handover.

---

## 10. `MaintenanceSchedulerService` — REQ-12

Computes the next service due for each car from its interval rules and current odometer, and keeps the
schedule current as work is completed.

```
recalculate(Car $c): void                    // after a completed log or odometer update
dueSoon(?Branch $b): Collection              // whichever of km / date arrives first
scheduleWork(Car $c, MaintenanceType $t, Carbon $when): MaintenanceLog   // also creates a CarBlock
complete(MaintenanceLog $l, Money $parts, Money $labour): void
```

`complete()` posts the expense stamped with `car_id`, updates the odometer, releases the calendar
block, and recalculates the next due point.

## 11. `FleetStatusService` — REQ-02

Owns the car status state machine and rejects illegal transitions.

```
transition(Car $c, CarStatus $to, string $reason, User $by): void
canTransition(Car $c, CarStatus $to): bool
syncFromBookings(): void      // pickup → rented, return → available, nightly drift repair
```

A car with an active booking cannot be set to `sold`; a car in `maintenance` cannot be picked up.
Without a single owner for these rules, status drifts and the dashboard counts stop matching reality.

---

## 12. `NotificationService` — REQ-17

Evaluates `alert_rules` on a schedule, decides who should be told what and when, deduplicates, and
dispatches through `MessagingService`.

```
evaluateAll(?CarbonImmutable $now): int          // hourly entry point, returns notifications queued
evaluate(AlertRule $rule, ?CarbonImmutable $now): int
raise(AlertRule $r, AlertSubject $s, ...): int   // queue for one subject, subject to dedup
alertOnce(AlertType $t, AlertSubject $s, ?int $branchId): int   // event-driven, no detector
```

Detection is delegated to one `AlertDetector` per `AlertType`, resolved through `DetectorRegistry`.
Recipients resolve from staff roles only — customers and owners have no logins.

Covers: return due tomorrow · booking overdue · customer payment overdue · owner instalment due ·
insurance / registration / inspection expiring · driving licence expiring · maintenance due ·
recurring expense due (office rent, internet, electricity) · cash variance detected.

**Deduplication is a first-class requirement.** An insurance policy expiring in 30 days must not
generate 30 identical alerts. Before sending, the service checks `notification_logs` for the same
`(template_key, related, channel)` within `repeat_every_days`. Alert fatigue makes the whole feature
worthless, so this is not an optimisation.

## 13. `MessagingService` — ADV-05

One interface over Mail, the in-app bell and Discord webhooks, resolved from
`config/notifications.php`. Handles templating, per-locale rendering, retries, and writes a
`notification_logs` row per attempt.

```
send(Channel $c, Recipient $r, string $templateKey, array $data, array $attachments = []): NotificationLog
sendMany(array $channels, ...): Collection
handleWebhook(string $provider, array $payload): void   // delivery receipts, read status
```

Channel drivers are swappable via config — the provider will change, and the calling code must not.
All sends are queued; a provider timeout must never block a receptionist mid-checkout.

---

## 14. `ReportService` — REQ-01, REQ-10, REQ-11, REQ-16, REQ-18

The single home for every aggregation over the ledger. Every dashboard widget, every export and every
profitability figure calls this — so "profit" has exactly one definition in the system.

```
dailyKpis(Carbon $d, ?Branch $b): DailyKpis
monthlyKpis(Carbon $m, ?Branch $b): MonthlyKpis
profitAndLoss(Period $p, ?Branch $b): ProfitLoss
carProfitability(Car $c, Period $p): CarProfitability     // REQ-11
fleetProfitability(Period $p): Collection
customerStatement(Customer $c, Period $p): Statement
cashFlow(Period $p): CashFlow
occupancyRate(Period $p, ?Branch $b): float
expenseBreakdown(Period $p): Collection
receivablesAgeing(?Carbon $asOf): Collection
export(ReportDefinition $d, ExportFormat $f): PendingExport   // PDF | XLSX | CSV, queued
```

Queries are read-only against `transactions`, cached with a tag flushed by `TransactionPosted`.
Exports run on the queue and notify the requester when the file is ready.

## 15. `BackupService` — ADV-04

Wraps `spatie/laravel-backup`: scheduled database + media backups, off-site copy, retention policy,
restore verification, and failure alerts to the manager.

```
runDatabaseBackup(): void
runFullBackup(): void
verifyLatest(): BackupHealth
```

A backup that has never been restored is a hypothesis. `verifyLatest()` restores the newest dump into
a scratch database and asserts row counts, on a schedule.

---

## Events

Emitted by services, consumed by listeners for side effects. Keeps notifications and cache
invalidation out of the business logic.

| Event | Consumers |
|---|---|
| `TransactionPosted` | flush report caches, update cash session, activity log |
| `BookingConfirmed` | reserve slot, generate contract draft, confirmation message |
| `BookingStarted` / `BookingReturned` | car status transition, odometer, closeout quote |
| `ContractSigned` | store hash, deliver PDF, activate booking |
| `PaymentReceived` | post to ledger, update schedule line, receipt to customer |
| `DepositRefunded` / `DepositDeducted` | post to ledger, notify customer |
| `FineAssigned` | notify customer, create receivable |
| `DocumentExpiringSoon` / `MaintenanceDue` | `NotificationService` |
| `CashSessionClosed` | post variance, alert on discrepancy |

---

## Testing expectation per service

Each service ships with unit tests in its phase. Non-negotiable ones, because these are where silent
money bugs live:

- `AccountingService` — every posting-matrix row asserted; reversal restores the prior balance;
  direct `Transaction::create()` from outside the service throws.
- `BookingAvailabilityService` — **a concurrency test** issuing two overlapping bookings in parallel
  transactions and asserting exactly one succeeds.
- `DepositService` — a held deposit never appears in revenue; deductions cannot exceed the deposit.
- `CashRegisterService` — balance from the ledger equals the sum of session movements plus float.
- `ReportService` — per-car profit equals hand-computed revenue minus expenses on a seeded fixture.
