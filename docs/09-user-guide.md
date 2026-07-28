# 09 — User Guide

How to run the business in Mcars, step by step.

This describes **what the application actually does today**, not what the design documents plan. Where
something is not built yet it says so plainly, so you never follow a step that leads nowhere.

Everyone works in one place: **`/admin`**. There is no customer or car-owner login — they are records
your staff manage, and anything they need to know, the office tells them.

---

## The one rule worth understanding

Every payment, expense, deposit and salary ends up in a single central record of money, called the
**ledger**. Cash in hand, profit, and how much each car earns are all *calculated from it*.

Nothing keeps its own running total. That is why:

- You cannot type in a customer's balance — it is worked out from what you invoiced and what they paid.
- **A ledger entry can never be edited or deleted.** A mistake is corrected by posting a reversal,
  which leaves both the error and the correction visible.
- If a step below says *"this posts to the accounts"*, skipping it means your reports will be wrong,
  even though the screen may look fine.

---

## Contents

1. [First-time setup](#1-first-time-setup)
2. [Adding a car](#2-adding-a-car)
3. [Cars you rent from an owner](#3-cars-you-rent-from-an-owner)
4. [Adding a customer](#4-adding-a-customer)
5. [The rental, start to finish](#5-the-rental-start-to-finish)
6. [Taking money](#6-taking-money)
7. [Security deposits](#7-security-deposits)
8. [Expenses](#8-expenses)
9. [The cash register](#9-the-cash-register)
10. [Traffic fines](#10-traffic-fines)
11. [Paying car owners](#11-paying-car-owners)
12. [Staff and payroll](#12-staff-and-payroll)
13. [Maintenance](#13-maintenance)
14. [Alerts](#14-alerts)
15. [The dashboard](#15-the-dashboard)
16. [Corrections](#16-corrections)
17. [Who can do what](#17-who-can-do-what)
18. [Not built yet](#18-not-built-yet)

---

## 1. First-time setup

Do this once, in this order. Later steps depend on earlier ones.

| # | Where | What |
|---|---|---|
| 1 | **Settings → Branches** | Your location(s). One is marked default. |
| 2 | **Settings → Users** and **Roles** | Create a login per staff member and give each exactly one role. |
| 3 | **Accounting → Chart of Accounts** | Already filled in by the installer. Do not renumber the codes. |
| 4 | **Accounting → Financial Accounts** | Your actual money containers: main cash box, safe, bank, CCP, BaridiMob, card terminal. |
| 5 | **Accounting → Expense Categories** | Each is linked to an accounting code. Fuel, maintenance, office rent and so on. |
| 6 | **Fleet → Car Categories** | Economy, SUV, Van… used for pricing and reporting. |
| 7 | **Fleet → Vendors** | Garages, insurers, parts suppliers. |
| 8 | **Bookings → Extras** | Chargeable add-ons: GPS, child seat, delivery, driver. |
| 9 | **Bookings → Contract Templates** | The contract wording, one per language (ar / fr / en). |
| 10 | **Settings → Alert Rules** | Already filled in with sensible lead times. Adjust to taste — see §14. |

> **Financial Accounts vs Chart of Accounts.** A *financial account* is a real place money sits and is
> what you pick when taking a payment. The *chart of accounts* is the bookkeeping structure behind it.
> Day to day you only touch financial accounts.

---

## 2. Adding a car

**Fleet → Cars → New.**

1. **Identity** — brand, model, year, colour, plate, chassis and engine numbers.
2. **Category and branch.**
3. **Ownership** — `company_owned`, or `third_party` if you rent it from someone (see §3).
4. **Rates** — daily rate at minimum; weekly and monthly are optional tiers. Also the standard security
   deposit, the daily kilometre allowance and the price per extra kilometre.
5. **Odometer** — the reading today. Keep this honest; maintenance scheduling depends on it.
6. **Photos** — stored privately, not on the public internet.

Then add its paperwork under **Fleet → Car Documents**: insurance, registration card (*carte grise*),
technical inspection, vignette. **Always fill in the expiry date** — that is what drives the renewal
alerts in §14. A document with no expiry date will never warn you.

### Car status

| Status | Meaning |
|---|---|
| Available | Bookable |
| Reserved | A confirmed booking has not been picked up yet |
| Rented | Out with a customer |
| In Maintenance | In the workshop |
| Out of Service | Accident, immobilised, waiting for parts |
| Sold / Returned to Owner | Finished — the car leaves the fleet |

Status changes on its own when you hand a car over and take it back. You rarely set it by hand.

---

## 3. Cars you rent from an owner

Third-party cars need three records, in order.

1. **Fleet → Car Owners → New** — the person or company, plus how you pay them (bank, CCP, BaridiMob).
2. **Fleet → Cars** — add the car with ownership type `third_party` and select the owner.
3. **Fleet → Ownership Agreements → New** — the deal itself:
   - `fixed_monthly` — you pay a set rent every month.
   - `revenue_share` — the owner takes a percentage of what the car earns.
   - `hybrid` — both.
   - Set the start date, the monthly amount and the number of instalments.

The agreement carries the terms, not the car. If the rent changes you end one agreement and start
another, and last year's instalments keep showing the rate that actually applied.

Paying the owner is §11.

---

## 4. Adding a customer

**CRM → Customers → New.**

- Choose **individual** or **company** — that changes which fields matter.
- For an individual: name, date and place of birth, national ID, address, phone.
- **Driving licence number and its expiry date.** Fill in the expiry — it drives the licence alert.
- For a company: trading name, trade register, *article* number.

Upload scans under the customer's **Documents**: ID, licence, proof of address.

The customer's page shows what they owe and what deposits you hold. **Both are calculated** from the
ledger — there is no field to type them into, and that is deliberate.

---

## 5. The rental, start to finish

This is the core workflow. Five steps, in order.

```
Draft ──► Confirmed ──► Active ──► Completed
  │           │            │
  │           │            └─ car back, extra charges invoiced
  │           └─ car handed over, rental invoiced   ◄── money starts here
  └─ quote only, nothing committed
```

### Step 1 — Create the booking

**Bookings → Bookings → New.** A four-step wizard:

1. **Customer & Car**
2. **Dates** — pick-up and expected return, and the branch for each
3. **Pricing** — daily rate, number of days, subtotal, extras, discount, total, deposit
4. **Options** — with driver, sales agent, additional drivers

It saves as **Draft**. Nothing is committed and no money moves.

### Step 2 — Confirm

Row action **Confirm**.

The car is now reserved for those dates. **The system physically cannot double-book it** — if a
colleague confirms an overlapping booking for the same car at the same moment, one succeeds and the
other is refused by the database. This is not a warning you can click past.

Draft bookings are deliberately *excluded* from that protection, so you can prepare several quotes for
the same car and period and only confirm one.

Still no money.

### Step 3 — Hand the car over ("Check out")

Row action **Check out (hand over)**. Enter:

- the actual hand-over time
- **the odometer reading**
- the fuel level

**This is where the money starts.** On confirming this step the system:

- invoices the rental to the customer — the full agreed total, not a deposit
- marks the car **Rented**

> **Why the whole amount now, not day by day?** Because the customer has committed and you have handed
> over the vehicle. Waiting until the return would show a three-month rental as earning nothing for
> three months.

Before handing over, it is worth recording a **Condition Report** (type `checkout`) noting existing
damage and the fuel level. It is your evidence at return time.

### Step 4 — Take the car back ("Check in")

Row action **Check in (return)**. Enter the return time, odometer and fuel.

The rental closes and the car goes back to **Available**.

If you first record a check-in **Condition Report**, the system compares it against the hand-over and
automatically invoices what is owed: late hours, extra kilometres, missing fuel, cleaning. **Without a
check-in condition report, no extra charges are calculated** — the rental simply closes.

### Step 5 — Settle up

Take payment (§6) and refund or deduct the deposit (§7).

### Cancelling

Row action **Cancel**, with a reason. Available at any point before the rental is finished.

### Contracts

**Bookings → Contracts** generates the contract from a template, in the customer's language, and
freezes its wording. **Render PDF** produces the document; **Send** delivers it.

Freezing matters: a contract signed today still prints exactly as signed, years later, after your
template and prices have changed.

---

## 6. Taking money

**Payments → Payments → New.**

| Field | Note |
|---|---|
| Direction | `inbound` = money in; `outbound` = a refund |
| Customer | Who is paying |
| Method | Cash, bank transfer, CCP, card, BaridiMob, cheque |
| Amount | Part-payments are fine — pay the rest later |
| Paid on | The date the money actually moved |
| Financial account | **Which cash box or bank it went into** |

**Saving it posts it to the accounts immediately.** You get a confirmation. The customer's balance and
your cash position both update at once.

- **Part payments** need no special handling. Record each one; what remains is simply the difference.
- **Instalment plans** live under **Payments → Payment Schedules** — one line per due date. Record the
  actual payments as normal; the schedule is the plan, the payments are the reality.

> If a payment ever fails to post you will get a red, persistent error. The row then shows a
> **Post to ledger** button so you can retry once the cause is fixed. If you do not see that button,
> the payment posted correctly.

---

## 7. Security deposits

**A deposit is not income.** It is money you are holding that still belongs to the customer, and the
system treats it that way — taking a 30 000 DZD deposit does not add 30 000 to your profit.

**Payments → Deposits.**

1. **Create** the deposit for the booking — amount, method, which account it went into.
2. **Hold deposit** — records it as money owed back to the customer.
3. At the end of the rental, either:
   - **Refund deposit** — leave the amount blank to return everything still held; or
   - **Deduct from deposit** — pick a reason (damage, missing fuel, late return, cleaning, extra
     kilometres, traffic fine), the amount, and a note.

Only the **deducted** part becomes income. The rest stays money you owe.

You cannot deduct more than the deposit — the system refuses, rather than letting you invent money the
customer never paid. If the damage genuinely exceeds the deposit, deduct the deposit in full and
invoice the difference separately.

---

## 8. Expenses

**Accounting → Expenses → New.**

1. Pick the **category** — this determines how it is classified in your accounts.
2. Enter the amount, the date, and a description.
3. **If the category is car-related** (fuel, wash, maintenance, insurance, registration), **you must
   choose the car.** An untagged fuel bill silently disappears from that car's profit — the number
   then looks fine and is wrong.
4. Optionally the supplier and invoice number.

Then move it along using the buttons at the top of the expense's own page:

**Submit for approval** → **Approve** → **Pay & Post** (choose which account it comes out of).

Only **Pay & Post** moves money. Approving records the decision, not the payment.

**Recurring expenses** — office rent, internet, electricity — are marked recurring with a next-due
date, and you get an alert before each one falls due.

---

## 9. The cash register

**Accounting → Cash Sessions.**

**Opening a shift** — create a session for a cash account with the float you are starting with.

**Closing a shift** — open the session and press **Close Session**, then enter **the amount you
physically counted**.

The system compares your count against what it expected:

- They match → the session closes cleanly.
- They differ → the session is marked **Disputed**, the difference is recorded as a real entry
  (cash over or cash short), and an alert is raised.

A discrepancy is never quietly absorbed. That is what makes the register trustworthy: the books are
forced to agree with the drawer, and any difference is visible and attributable.

**Accounting → Transactions** is the full money log — searchable by date, account, car, customer.
It is **view-only by design**; see §16.

---

## 10. Traffic fines

**Operations → Fines → New** — record the notice: car, type, violation date and time, amount, authority.

Then:

1. **Suggest who is liable** — the system checks which rental was running at the moment of the offence
   and proposes the customer or the company. It **only suggests**; the time of an offence is often
   ambiguous around handover, and assigning legal liability automatically is not acceptable.
2. **Assign liability** — you confirm:
   - **Customer** → recorded as a debt they owe. Recover it as a payment, or deduct it from their
     deposit (§7).
   - **Company** → recorded as a cost the business absorbs.

A fine you pay and then recover from the customer does not count as either profit or loss — it passes
straight through.

---

## 11. Paying car owners

**Payments → Owner Instalments.**

1. Create the instalment for the month (agreement, car, owner, due date, amount).
2. **Record as owed** — books this month's rent as a cost *of that car* and as money owed to the owner.
   Do this once per instalment.
3. Pay the owner via **Payments → Payments** with direction `outbound`.

Step 2 is what makes a third-party car's profit honest: the rent is tagged to the car, so the car reads
*rental income − owner rent − fuel − maintenance*. Skip it and the car looks far more profitable than
it is.

What you owe an owner is calculated from these records — there is no balance field.

---

## 12. Staff and payroll

- **HR → Employees** — one record per staff member, with salary and contract type.
- **HR → Employee Advances** — money advanced against a future salary. This is a loan, not a cost, and
  is recovered from the next payroll.
- **HR → Commissions** — sales commission per booking.
- **HR → Payroll Runs** — one per month:
  1. Create the run and its lines.
  2. **Approve** — records salaries, employer contributions and commissions as amounts owed to staff.
  3. **Mark as paid** — records the money leaving and clears what was owed.

Keeping approve and pay separate lets you see what you owe staff before payday.

---

## 13. Maintenance

- **Fleet → Maintenance Schedules** — the rules: service every *N* kilometres or every *N* days, and
  how far ahead to warn.
- **Fleet → Maintenance Logs** — the work actually done, with parts and labour cost.

Servicing is due on **whichever comes first, distance or time**. A car doing 400 km a month hits the
date first; a taxi hits the kilometres first. Watching only one misses half the fleet — so keep the
odometer readings current, which happens naturally if you record them at every hand-over and return.

---

## 14. Alerts

The system checks every hour and tells you what needs attention.

**What it watches:** returns due · overdue rentals · overdue customer payments · owner instalments due ·
vehicle documents expiring · driving licences expiring · maintenance due · recurring expenses due ·
cash discrepancies.

**Where alerts arrive:** the **bell** in the top bar, by **email**, and on **Discord** if a webhook is
configured.

**Settings → Alert Rules** — one rule per alert type, and every dial is yours:

| Setting | Meaning |
|---|---|
| Lead time (days) | How far ahead to warn. `0` reacts on the day. |
| Repeat every (days) | How often to remind about the same thing. Empty = tell me once, ever. |
| Max repeats | A hard ceiling per item. |
| Channels | Bell, email, Discord. |
| Recipients | Which roles get it. |
| Branch | Empty = all branches. A branch rule overrides the global one. |

> **Why the repeat settings matter.** An insurance policy expiring in 30 days should produce a handful
> of reminders, not thirty. A system that cries wolf daily gets ignored — and then the one alert that
> mattered is missed too. The defaults are already tuned for this; raise the repeat interval rather
> than switching a rule off.

**Per-user daily digest** — a user can opt to receive one summary email a day instead of individual
ones. The bell is unaffected.

**Settings → Delivery Log** — every message sent, its status and any error. View-only.

---

## 15. The dashboard

The landing page of `/admin`.

**Today:** cars available / rented / in maintenance · returns due today · upcoming pick-ups · overdue
returns · today's income, spending and profit · cash in hand.

**This month and trend:** income vs spending (12 months) · profit trend · cash flow · fleet occupancy ·
most profitable cars · best customers · spending by category · unpaid customer invoices by age.

Every figure is calculated from the ledger. Two consequences:

- They are only as accurate as your discipline in §5–§8. A hand-over that was never checked out shows
  no income.
- Money figures are **hidden from staff without financial permission**. A receptionist sees returns,
  pick-ups and cash in hand, but not profit or margin.

---

## 16. Corrections

**The ledger cannot be edited or deleted.** There is no button, and the database itself refuses.

To correct a mistake, open **Accounting → Transactions**, find the entry, and use **Reverse** with a
reason. That posts an opposite entry which cancels the original. You then record the correct one.

Both remain visible.

> **Why it works this way.** A figure that can be quietly changed after the fact cannot be defended to
> an owner, an auditor or an inspector. Reversals also show what someone believed and when they learned
> otherwise — which is exactly what any investigation needs.

---

## 17. Who can do what

| Area | Manager | Accountant | Receptionist | Maintenance | Supervisor |
|---|:--:|:--:|:--:|:--:|:--:|
| Fleet | full | read | read | full (maintenance) | read |
| Customers | full | read | full | — | read |
| Bookings | full | read | full | read | full |
| Money | full | full | payments + cash only | — | read |
| Staff / payroll | full | payroll | — | — | read |
| Fines | full | financial side | assign | — | full |
| Settings | full | — | — | — | — |

Permissions are enforced by the system, not by hiding menus.

`super_admin` has everything, including alert rules and the delivery log.

---

## 18. Not built yet

Stated plainly so you do not go looking.

| Feature | Status |
|---|---|
| **Booking calendar** (drag-and-drop timeline) | Not built. Bookings work from the table view. |
| **Reports and exports** (PDF / Excel) | Phase 9. Figures are on the dashboard; there is no export button. |
| **Owner statements** | The data exists; the printable statement is not built. |
| **Activity log** (who changed what) | Phase 10. |
| **Automatic backups** | Phase 10. **Take your own backups until then.** |
| **Multi-branch enforcement** | Branch fields exist and alerts respect them, but a user is not yet restricted to their branch everywhere. |
| **Backup-failure alert** | The rule exists but nothing triggers it until backups are built. |
| **Depreciation on owned cars** | Not posted, so a company-owned car looks slightly more profitable than an equivalent rented one. |

Two operational notes:

- **The hourly alert check and the daily digest need the scheduler running.** If alerts go quiet, that
  is the first thing to check.
- **Emails and Discord messages are sent in the background.** A slow provider never blocks the counter,
  but it does mean a message may arrive a moment after the action.

---

*Design and architecture live in the other documents in this folder. This one is for the people using
the system.*
