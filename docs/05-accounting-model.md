# 05 — Accounting Model & Posting Rules

**Read this before writing any code that touches money.** It is the specification for
`AccountingService` and every Poster.

---

## The model in one paragraph

One table, `transactions`. Each row is a **balanced double-entry posting**: a positive `amount`, one
`debit_account_id`, one `credit_account_id`, plus dimensions (`car_id`, `customer_id`, `car_owner_id`,
`employee_id`, `booking_id`, `branch_id`). Nothing else in the system stores a balance. Cash on hand,
profit, per-car profitability, what a customer owes, what an owner is owed — all are `SUM` queries
over this one table.

### Why not a simpler signed ledger

A flat "income / expense with a +/− amount" ledger cannot represent four things this business does
every day:

| Situation | Flat ledger gets it wrong | Double-entry gets it right |
|---|---|---|
| Customer pays a 30 000 DZD deposit | Cash goes up, so it looks like income. **Profit is overstated by 30 000 and the refund later looks like a loss.** | Dr Cash / Cr *Security Deposits Held* — a liability. Profit unaffected. |
| Customer rents on credit, pays next week | Either revenue is invisible until paid, or a second table tracks "amounts owed" | Dr *Receivable* / Cr *Revenue* now; Dr Cash / Cr *Receivable* on payment |
| Owner's monthly rent is due but unpaid | Expense either missing or double-counted when paid | Dr *Owner Rent Expense* / Cr *Payable–Owners*; payment clears the payable |
| Fine paid to the authority, recharged to the customer | Looks like both an expense and income | Dr *Fines Receivable* / Cr *Fines Payable* — neither touches profit |

Each of these is explicitly required (REQ-03, REQ-04, REQ-14, ADV-07). Under a flat ledger, every one
of them needs its own side table with its own running total — which is precisely what the core
constraint forbids.

### Multi-leg entries

A single row expresses two accounts. An event needing three or more legs (rental + tax, card
settlement net of fees) is posted as **several balanced rows** written atomically by
`AccountingService::postMany()`, sharing a `meta->group_uuid`. Reports treat the group as one
document; the ledger stays two-column and every row remains individually balanced.

---

## Chart of accounts (seed)

`is_cash_equivalent = true` on 1010–1050 — this flag is what the cash register reads.
Codes are stable; do not renumber after go-live.

### 1xxx — Assets
| Code | Account | Notes |
|---|---|---|
| 1000 | Cash & Bank | heading, not postable |
| **1010** | Main Cash Box | cash-equivalent, per branch |
| **1015** | Safe / Reserve | cash-equivalent |
| **1020** | Bank Account | cash-equivalent |
| **1030** | CCP Account | cash-equivalent |
| **1040** | BaridiMob Wallet | cash-equivalent |
| **1050** | Card / POS Clearing | cash-equivalent; cleared on settlement |
| 1100 | Receivables | heading |
| 1110 | Accounts Receivable – Customers | REQ-04 "amounts owed" |
| 1120 | Fines Receivable – Customers | REQ-14 |
| 1130 | Employee Advances | REQ-15 |
| 1140 | Other Receivables | |
| 1200 | Fixed Assets | heading |
| 1210 | Vehicles – Company Owned | |
| 1215 | Accumulated Depreciation – Vehicles | contra-asset |
| 1220 | Office Equipment | |
| 1300 | Prepayments | heading |
| 1310 | Prepaid Insurance | optional, see "Refinements" |
| 1320 | Prepaid Rent | |

### 2xxx — Liabilities
| Code | Account | Notes |
|---|---|---|
| **2100** | Security Deposits Held | ADV-07. **Never revenue.** |
| 2200 | Accounts Payable – Car Owners | REQ-03 |
| 2210 | Accounts Payable – Suppliers | |
| 2220 | Fines Payable – Authorities | REQ-14 |
| 2300 | Salaries Payable | REQ-15 |
| 2310 | Social Contributions Payable | |
| 2500 | Customer Credit Balances | overpayments held on account |
| **2600** | **Inter-branch Clearing** | **clearing, excluded from company-wide reports** |

### 3xxx — Equity
| Code | Account |
|---|---|
| 3000 | Owner Capital |
| 3100 | Retained Earnings |
| 3200 | Drawings |

### 4xxx — Revenue (REQ-08)
| Code | Account | Source |
|---|---|---|
| 4010 | Rental Revenue | core rentals |
| 4020 | Additional Services Revenue | extras: GPS, child seat, delivery, driver |
| 4030 | Late Return Fees | |
| 4040 | Excess Mileage Revenue | |
| 4050 | Fuel Recharge Revenue | returned under-fuelled |
| 4060 | Damage Recovery Revenue | |
| 4070 | Fine Recharge Revenue | administrative handling fee only |
| 4080 | Cleaning Fees | |
| 4090 | Forfeited Deposits | |
| 4900 | Cash Over / Misc Income | register variance |

### 5xxx — Expenses (REQ-08)
| Code | Account | Car-related |
|---|---|:--:|
| 5010 | Owner Car Rent | ✔ |
| 5020 | Fuel | ✔ |
| 5030 | Car Wash & Cleaning | ✔ |
| 5040 | Maintenance & Repairs | ✔ |
| 5050 | Insurance | ✔ |
| 5060 | Taxes & Registration | ✔ |
| 5070 | Office Rent | |
| 5080 | Salaries & Wages | |
| 5085 | Social Contributions | |
| 5090 | Commissions | |
| 5100 | Internet & Telecom | |
| 5110 | Electricity & Water | |
| 5120 | Marketing & Advertising | |
| 5130 | Bank & Payment Charges | |
| 5140 | Fines Absorbed by Company | ✔ |
| 5150 | Depreciation – Vehicles | ✔ |
| 5160 | Office Supplies | |
| 5170 | Professional Fees | |
| 5900 | Cash Short / Other | |

Accounts marked *car-related* have `expense_categories.is_car_related = true`, which makes `car_id`
**mandatory** on the expense form. This is what keeps per-car profitability (REQ-11) honest — an
untagged fuel expense silently disappears from every car's P&L.

---

## Posting matrix

`Dr` = debit account, `Cr` = credit account. "Dimensions" lists what must be stamped on the row.

### Bookings & rental revenue

| # | Event | Dr | Cr | Amount | Dimensions |
|---|---|---|---|---|---|
| E01 | Booking **confirmed** | — | — | — | **No posting.** A confirmed booking is a commitment, not a financial event. Posting here would book revenue for rentals that never start. |
| E02 | Contract activated / car picked up — rental invoiced | 1110 AR–Customers | 4010 Rental Revenue | booking net total | car, booking, contract, customer, branch |
| ~~E03~~ | ~~…tax portion~~ | — | — | — | **Removed.** No tax is charged. `bookings.tax_rate`/`tax_amount` and account 2400 no longer exist; a rental posts its full total through E02. |
| E04 | Extras invoiced | 1110 AR–Customers | 4020 Additional Services | extras total | car, booking, customer |
| E05 | Late return fee at closeout | 1110 AR–Customers | 4030 Late Return Fees | fee | car, booking, customer |
| E06 | Excess mileage at closeout | 1110 AR–Customers | 4040 Excess Mileage | km × rate | car, booking, customer |
| E07 | Fuel shortfall at closeout | 1110 AR–Customers | 4050 Fuel Recharge | shortfall | car, booking, customer |
| E08 | Cleaning charge at closeout | 1110 AR–Customers | 4080 Cleaning Fees | fee | car, booking, customer |
| E09 | Booking cancelled after invoicing | *reversal of E02–E08* | | | reason mandatory |

> **Revenue recognition point: contract activation (pickup), for the full contracted amount.**
> Not at confirmation (rentals fall through), not at return (a 3-month rental would show no revenue for
> 3 months). Closeout adjustments post additionally. If the business later wants day-by-day accrual for
> long rentals, that is a scheduled job posting daily slices — decide before Phase 4, changing it after
> go-live means restating history.

### Customer payments (REQ-07)

| # | Event | Dr | Cr | Dimensions |
|---|---|---|---|---|
| E10 | Payment in cash | 1010 Main Cash Box | 1110 AR–Customers | customer, booking, cash_session |
| E11 | Bank transfer | 1020 Bank | 1110 AR–Customers | customer, booking |
| E12 | CCP | 1030 CCP | 1110 AR–Customers | customer, booking |
| E13 | BaridiMob | 1040 BaridiMob | 1110 AR–Customers | customer, booking |
| E14 | Card — capture | 1050 POS Clearing | 1110 AR–Customers | customer, booking |
| E15 | Card — settlement (net) | 1020 Bank | 1050 POS Clearing | — |
| E16 | Card — processor fee | 5130 Bank Charges | 1050 POS Clearing | same `group_uuid` as E15 |
| E17 | **Partial payment** | *as E10–E14, smaller amount* | | Remaining balance = AR balance for that customer. No `paid_amount` column anywhere. |
| E18 | Instalment payment | *as E10–E14* | | additionally allocated to a `payment_schedules` line |
| E19 | Overpayment | 1010 Cash | 2500 Customer Credit Balances | customer |
| E20 | Cheque bounced | 1110 AR–Customers | 1020 Bank | *reversal-style*; plus Dr 5130 / Cr 1020 for the fee |
| E21 | Refund to customer | 1110 AR–Customers | 1010 Cash | customer — leaves a debit balance if no offsetting revenue |

### Security deposits (ADV-07) — **a deposit is a liability, never revenue**

| # | Event | Dr | Cr | Dimensions |
|---|---|---|---|---|
| E22 | Deposit received | 1010 Cash *(or 1020/1030/1040)* | **2100 Security Deposits Held** | customer, booking, contract |
| E23 | Deposit refunded in full | 2100 Security Deposits Held | 1010 Cash | customer, booking |
| E24 | Deduction — damage | 2100 Security Deposits Held | 4060 Damage Recovery | car, customer, booking |
| E25 | Deduction — fuel | 2100 Security Deposits Held | 4050 Fuel Recharge | car, customer, booking |
| E26 | Deduction — late return | 2100 Security Deposits Held | 4030 Late Return Fees | car, customer, booking |
| E27 | Deduction — cleaning | 2100 Security Deposits Held | 4080 Cleaning Fees | car, customer, booking |
| E28 | Deduction — traffic fine | 2100 Security Deposits Held | 1120 Fines Receivable | car, customer, fine |
| E29 | Deposit forfeited entirely | 2100 Security Deposits Held | 4090 Forfeited Deposits | customer, booking |
| E30 | Deduction exceeds deposit | *deposit fully applied per E24–E28, remainder:* 1110 AR–Customers / Cr revenue account | | customer, booking |
| E31 | Partial refund of remainder | 2100 Security Deposits Held | 1010 Cash | customer |

The balance of 2100 filtered to a customer is exactly what the customer page's "Deposit held" shows,
and its total is money the company is holding but does not own — visible on the balance sheet, absent
from profit.

### Car owners (REQ-03)

| # | Event | Dr | Cr | Dimensions |
|---|---|---|---|---|
| E32 | Monthly instalment generated — fixed rent | **5010 Owner Car Rent** | 2200 AP–Car Owners | **car**, car_owner, agreement, branch |
| E33 | Revenue-share settled at period close | 5010 Owner Car Rent | 2200 AP–Car Owners | car, car_owner — amount = share% × that car's gross rental revenue for the period |
| E34 | Instalment paid in cash | 2200 AP–Car Owners | 1010 Cash | car_owner, car, cash_session |
| E35 | Instalment paid by transfer / CCP | 2200 AP–Car Owners | 1020 / 1030 | car_owner, car |
| E36 | Instalment waived | 2200 AP–Car Owners | 5010 Owner Car Rent | *reversal-style*; reason mandatory |
| E37 | Company deducts a cost from owner's rent | 2200 AP–Car Owners | 1110 or relevant asset | car_owner — must be permitted by the agreement |

E32 is why per-car profitability works. The rent is stamped with `car_id`, so a third-party car's P&L
naturally reads *rental revenue − owner rent − fuel − maintenance*. **Owner remaining balance (REQ-03)
is the balance of 2200 filtered by `car_owner_id`** — never a stored column. In the UI this is the
"Record as owed" action on an owner instalment.

### Expenses (REQ-08)

| # | Event | Dr | Cr | Dimensions |
|---|---|---|---|---|
| E38 | Expense approved, unpaid (on credit) | 5xxx per category | 2210 AP–Suppliers | car *(if car-related)*, vendor, expense_category |
| E39 | Expense paid immediately | 5xxx per category | 1010 / 1020 / 1030 | car, vendor, category, cash_session |
| E40 | Supplier paid later | 2210 AP–Suppliers | 1010 / 1020 | vendor |
| E41 | Maintenance completed | 5040 Maintenance & Repairs | 1010 / 1020 / 1030 or 2210 | **car**, vendor, maintenance_log |
| E42 | Insurance renewed | 5050 Insurance | 1010 / 1020 / 1030 or 2210 | **car**, car_document |
| E42b | Registration card / road-tax vignette / technical inspection renewed | 5060 Taxes & Registration | 1010 / 1020 / 1030 or 2210 | **car**, car_document |
| E42c | GPS subscription renewed for a car | 5100 Internet & Telecom | 1010 / 1020 / 1030 or 2210 | **car**, car_document |
| E43 | Fuel | 5020 Fuel | 1010 | **car** *(required)* |
| E44 | Car wash | 5030 Car Wash | 1010 | **car** |
| E45 | Registration / road tax | 5060 Taxes & Registration | 1010 | **car** |
| E46 | Office rent | 5070 Office Rent | 1010 / 1020 | branch only |
| E47 | Internet, electricity, marketing | 5100 / 5110 / 5120 | 1010 / 1020 | branch only |
| E48 | Depreciation, company-owned car (monthly) | 5150 Depreciation | 1215 Accum. Depreciation | **car** |

E48 matters for fairness: without it, a company-owned car shows no cost of capital and always appears
more profitable than an identical third-party car paying rent. Optional in Phase 4, recommended
before comparing the two fleets in reports.

**E42, E42b and E42c are one code path** — `App\Services\Fleet\RecordDocumentRenewalService`, whose
draft comes from `MaintenancePoster::postDocumentRenewed()`. The expense account follows
`car_documents.type`; the row split above exists so each account choice is stated rather than
inferred. Three things they deliberately clarify, because each one contradicts a neighbouring row
if you read it quickly:

- **E42b overlaps E45 on purpose.** Both debit 5060. E45 is an ad-hoc cash payment recorded as an
  `Expense`; E42b is a renewal backed by a `car_documents` row, so it carries the `car_document`
  dimension and may be left on credit. Same account, different source document.
- **E42c is the car-dimensioned counterpart of E47.** E47 says "branch only" for 5100 because office
  internet belongs to a branch, not a vehicle. A GPS subscription is billed per tracked car, and
  REQ-02 names GPS explicitly, so it *must* carry `car_id` or per-car profitability loses it. E47's
  "branch only" constrains E47, not the account.
- **The 2210 leg is the E38 accrual shape**, not a new idea: "expense approved, unpaid → 5xxx per
  category / 2210". Choosing a financial account pays now; leaving it empty owes the supplier.

`ownership_title`, `purchase_invoice` and `other` have **no** row here and none is intended — an
ownership title is acquisition paperwork, not a running cost. `RecordDocumentRenewalService::isPostable()`
refuses them, so an unmapped type fails loudly instead of guessing an account.

### Fines (REQ-14)

| # | Event | Dr | Cr | Dimensions |
|---|---|---|---|---|
| E49 | Fine received, **customer** liable | 1120 Fines Receivable | 2220 Fines Payable | car, customer, contract, fine — **profit untouched** |
| E50 | Fine received, **company** liable | 5140 Fines Absorbed | 2220 Fines Payable | car, fine |
| E51 | Company pays the authority | 2220 Fines Payable | 1010 / 1020 | fine |
| E52 | Recovered from customer in cash | 1010 Cash | 1120 Fines Receivable | customer, fine |
| E53 | Recovered from deposit | *see E28* | | |
| E54 | Handling fee charged to customer | 1110 AR–Customers | 4070 Fine Recharge Revenue | customer, fine |
| E55 | Unrecoverable — written off | 5140 Fines Absorbed | 1120 Fines Receivable | fine — approval required |
| E56 | Owner liable (their fault) | 2200 AP–Car Owners | 2220 Fines Payable | car_owner, car |

### Payroll (REQ-15)

| # | Event | Dr | Cr | Dimensions |
|---|---|---|---|---|
| E57 | Payroll approved — gross salary | 5080 Salaries & Wages | 2300 Salaries Payable | employee, payroll_item |
| E58 | Employer social contributions | 5085 Social Contributions | 2310 Social Contributions Payable | employee |
| E59 | Commissions accrued | 5090 Commissions | 2300 Salaries Payable | employee, booking |
| E60 | Net salary paid | 2300 Salaries Payable | 1010 / 1020 | employee, cash_session |
| E61 | Employee advance given | **1130 Employee Advances** *(asset)* | 1010 Cash | employee |
| E62 | Advance recovered from payroll | 2300 Salaries Payable | 1130 Employee Advances | employee |
| E63 | Social contributions remitted | 2310 Social Contrib. Payable | 1020 Bank | |

An advance is an asset, not an expense — E61 must not touch 5080, or the salary is counted twice.

### Cash register (REQ-09)

| # | Event | Dr | Cr | Dimensions |
|---|---|---|---|---|
| E64 | Opening float drawn from the safe | 1010 Main Cash Box | 1015 Safe | cash_session, branch |
| E65 | Float returned to the safe at close | 1015 Safe | 1010 Main Cash Box | cash_session |
| E66 | Cash banked | 1020 Bank | 1010 Main Cash Box | cash_session |
| E67 | Transfer between boxes / branches | 1010 *(dest)* | 1010 *(source)* | both branch ids |
| E68 | **Cash over** at close (counted > expected) | 1010 Main Cash Box | 4900 Cash Over | cash_session — raises an alert |
| E69 | **Cash short** at close (counted < expected) | 5900 Cash Short | 1010 Main Cash Box | cash_session — raises an alert |
| E70 | Capital injected | 1010 / 1020 | 3000 Owner Capital | |
| E71 | Owner drawings | 3200 Drawings | 1010 / 1020 | |

E68/E69 are the reason the register can be trusted: the ledger is forced to agree with the physical
count, and the difference is a visible, attributable, alertable entry rather than a quiet edit.

---

## Corrections — reversal only

`transactions` is append-only. No `UPDATE`, no `DELETE`, no soft delete. Blocked in the Eloquent model
*and* by a Postgres trigger.

A mistake is corrected by `AccountingService::reverse()`, which posts a new row with the debit and
credit accounts swapped, the same amount and dimensions, `is_reversal = true`, a link in both
directions (`reverses_transaction_id` / `reversed_by_transaction_id`), and a **mandatory reason**. The
correct entry is then posted normally.

Why: an editable ledger is not evidence. If a figure can be changed after the fact, no report derived
from it can be defended to an owner, an auditor or a tax inspector — and every reconciliation becomes
an argument. Reversals also mean the audit trail shows *what someone thought was true and when they
learned otherwise*, which is exactly what an investigation needs.

Reversal is permission-gated (`accountant`, `manager`) and always written to `activity_log`.

---

## Derivation queries

Every figure the system displays. These are the reference implementations for `ReportService`.

### Cash on hand (REQ-09) — real-time

```sql
SELECT
    COALESCE(SUM(CASE WHEN t.debit_account_id  = :account THEN t.amount END), 0)
  - COALESCE(SUM(CASE WHEN t.credit_account_id = :account THEN t.amount END), 0) AS balance
FROM transactions t
WHERE :account IN (t.debit_account_id, t.credit_account_id)
  AND t.occurred_on <= :as_of;
```

Total cash = the same over all accounts with `is_cash_equivalent = true`.

### Generic account balance (respects normal balance)

```sql
SELECT a.id, a.code, a.name, a.type,
       CASE WHEN a.normal_balance = 'debit'
            THEN SUM(CASE WHEN t.debit_account_id  = a.id THEN t.amount ELSE -t.amount END)
            ELSE SUM(CASE WHEN t.credit_account_id = a.id THEN t.amount ELSE -t.amount END)
       END AS balance
FROM chart_of_accounts a
JOIN transactions t ON a.id IN (t.debit_account_id, t.credit_account_id)
WHERE t.occurred_on <= :as_of
GROUP BY a.id, a.code, a.name, a.type, a.normal_balance;
```

### Profit & loss (REQ-10) — daily, weekly, monthly, yearly

```sql
SELECT
    SUM(CASE WHEN cr.type = 'revenue' THEN t.amount ELSE 0 END)
  - SUM(CASE WHEN dr.type = 'revenue' THEN t.amount ELSE 0 END) AS revenue,
    SUM(CASE WHEN dr.type = 'expense' THEN t.amount ELSE 0 END)
  - SUM(CASE WHEN cr.type = 'expense' THEN t.amount ELSE 0 END) AS expenses
FROM transactions t
JOIN chart_of_accounts dr ON dr.id = t.debit_account_id
JOIN chart_of_accounts cr ON cr.id = t.credit_account_id
WHERE t.occurred_on BETWEEN :from AND :to
  AND (:branch IS NULL OR t.branch_id = :branch);
-- net profit = revenue - expenses
```

The subtraction of the opposite side is not decorative — it is what makes reversals and refunds reduce
revenue instead of inflating expenses.

### Per-car profitability (REQ-02, REQ-11)

```sql
WITH ledger AS (
    SELECT t.car_id,
           SUM(CASE WHEN cr.type = 'revenue' THEN t.amount ELSE 0 END)
         - SUM(CASE WHEN dr.type = 'revenue' THEN t.amount ELSE 0 END) AS revenue,
           SUM(CASE WHEN dr.type = 'expense' THEN t.amount ELSE 0 END)
         - SUM(CASE WHEN cr.type = 'expense' THEN t.amount ELSE 0 END) AS expenses
    FROM transactions t
    JOIN chart_of_accounts dr ON dr.id = t.debit_account_id
    JOIN chart_of_accounts cr ON cr.id = t.credit_account_id
    WHERE t.car_id IS NOT NULL
      AND t.occurred_on BETWEEN :from AND :to
    GROUP BY t.car_id
),
usage AS (
    SELECT b.car_id,
           SUM(EXTRACT(EPOCH FROM (
                 LEAST(COALESCE(b.actual_return_at, b.expected_return_at), :to::timestamptz)
               - GREATEST(b.actual_pickup_at, :from::timestamptz))) / 86400) AS rental_days
    FROM bookings b
    WHERE b.status IN ('active', 'completed', 'overdue')
      AND b.actual_pickup_at < :to AND COALESCE(b.actual_return_at, b.expected_return_at) > :from
    GROUP BY b.car_id
)
SELECT c.id, c.registration_number, c.brand, c.model,
       COALESCE(l.revenue, 0)                        AS revenue,
       COALESCE(l.expenses, 0)                       AS expenses,
       COALESCE(l.revenue, 0) - COALESCE(l.expenses, 0) AS net_profit,
       COALESCE(u.rental_days, 0)                    AS rental_days,
       ROUND(COALESCE(u.rental_days, 0) / NULLIF(:period_days, 0) * 100, 1) AS utilisation_pct
FROM cars c
LEFT JOIN ledger l ON l.car_id = c.id
LEFT JOIN usage  u ON u.car_id = c.id
ORDER BY net_profit DESC;
```

Top row = "top-performing car by profit" (REQ-01).

> **Decided in Phase 7: the denominator is calendar days — it does NOT subtract maintenance-blocked
> days.** Downtime is lost earning capacity and the KPI is meant to show it; dividing by rentable days
> only would report a car that spent two weeks off the road as 100% utilised. `ReportService` applies
> this one definition to the widget, the car page, the fleet report *and* `occupancyRate()`. See
> [`tasks/phase-07-dashboards.md`](tasks/phase-07-dashboards.md).

### Customer outstanding balance (REQ-04)

```sql
SELECT
    SUM(CASE WHEN t.debit_account_id  IN (1110, 1120) THEN t.amount ELSE 0 END)
  - SUM(CASE WHEN t.credit_account_id IN (1110, 1120) THEN t.amount ELSE 0 END) AS owed
FROM transactions t
WHERE t.customer_id = :customer;
```
Positive = the customer owes the company. Negative = credit balance. Deposits are **not** included —
they sit in 2100 and are shown separately.

### Owner remaining balance (REQ-03)

```sql
SELECT
    SUM(CASE WHEN t.credit_account_id = 2200 THEN t.amount ELSE 0 END)
  - SUM(CASE WHEN t.debit_account_id  = 2200 THEN t.amount ELSE 0 END) AS owed_to_owner
FROM transactions t
WHERE t.car_owner_id = :owner;
```

### Cash flow (REQ-18)

```sql
SELECT date_trunc('month', t.occurred_on) AS month,
       SUM(CASE WHEN dr.is_cash_equivalent THEN t.amount ELSE 0 END) AS cash_in,
       SUM(CASE WHEN cr.is_cash_equivalent THEN t.amount ELSE 0 END) AS cash_out
FROM transactions t
JOIN chart_of_accounts dr ON dr.id = t.debit_account_id
JOIN chart_of_accounts cr ON cr.id = t.credit_account_id
WHERE t.occurred_on BETWEEN :from AND :to
  AND (dr.is_cash_equivalent OR cr.is_cash_equivalent)
  AND NOT (dr.is_cash_equivalent AND cr.is_cash_equivalent)  -- exclude internal transfers
GROUP BY 1 ORDER BY 1;
```

Excluding cash-to-cash transfers is essential — banking the till would otherwise show as both an
inflow and an outflow and double the apparent turnover.

### Expense breakdown by category (REQ-18)

```sql
SELECT ec.name,
       SUM(CASE WHEN t.debit_account_id = ec.ledger_account_id THEN t.amount ELSE -t.amount END) AS total
FROM transactions t
JOIN expense_categories ec ON ec.ledger_account_id IN (t.debit_account_id, t.credit_account_id)
WHERE t.occurred_on BETWEEN :from AND :to
GROUP BY ec.name ORDER BY total DESC;
```

### Fleet occupancy (REQ-01, REQ-18)

Not a ledger query — computed from `bookings`:

```
occupancy = Σ rented car-days ÷ Σ calendar car-days
calendar car-days = car count × days in period
```

> **Superseded formula.** This section previously divided by *available* car-days
> (`days in period − days blocked − days out_of_service`). Phase 7 settled on calendar days for the
> reason given under §Per-car profitability above; `car_blocks` is not consulted. Both figures come
> from `ReportService`, so occupancy and per-car utilisation always agree.

### Accrual vs cash

The P&L above is **accrual** — revenue at pickup, expenses when incurred. The cash flow report is
**cash** — actual movements of cash-equivalent accounts. Both are derived from the same rows; nothing
is stored twice. When the manager asks "we made 400 000 profit, why is there only 150 000 in the till",
the answer is receivables and deposits, and both reports are there to show it.

---

## Integrity checks

Run nightly; alert the manager on failure.

| Check | Assertion |
|---|---|
| Trial balance | Σ over accounts of (debits − credits) = 0 |
| Cash reconciliation | ledger balance of each cash account = last counted amount + movements since |
| Deposits | balance of 2100 = Σ of `deposits` with status `held`/`partially_refunded` less deductions |
| Receivables | balance of 1110 per customer = Σ invoiced − Σ received for that customer |
| Owner payables | balance of 2200 per owner = Σ accrued instalments − Σ paid |
| Immutability | no `transactions` row has `updated_at > created_at` |
| Orphan dimensions | no transaction on a car-related expense account has `car_id IS NULL` |

The last one is the quiet killer of per-car profitability: one untagged expense and the numbers are
wrong in a way no one notices for months.

---

## Refinements deliberately deferred

Recorded so they are choices, not oversights.

- **Prepaid insurance amortisation** (1310) — a 12-month premium currently hits one month's P&L. Fine
  for a small fleet; add monthly amortisation if it distorts monthly profit.
- **Multi-currency** — schema carries `currency` and `exchange_rate`; no revaluation logic. Add only
  if the business actually transacts in EUR.
- **Formal period close** — no locked accounting periods in v1. Add a `closed_at` guard on
  `occurred_on` before the first tax filing.
- ~~**VAT/TVA regime**~~ — **closed.** The business does not charge tax. Account 2400 (Taxes
  Payable), the E03 posting, `TransactionType::Tax` and the tax columns on `bookings` and `expenses`
  were all removed. Account 5060 (Taxes & Registration) stays: it is an *expense* category for
  vehicle registration and vignette costs, which the business does pay.
