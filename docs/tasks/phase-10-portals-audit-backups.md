# Phase 10 — Portals, Audit, Backups, Multi-Branch

**Status: ⬜** · Depends on: all previous · Closes: **REQ-19**, **ADV-03**, **ADV-04**, **ADV-06**,
**ADV-08**, **ADV-09**

External users get their own doors; the system becomes operationally safe to run.

## Read first
[`../02-filament-panels.md`](../02-filament-panels.md) §Isolation — all four layers ·
[`../08-multi-branch-retrofit.md`](../08-multi-branch-retrofit.md) §3–4 — the switcher design and the
hazards, which apply when *switching enforcement on* just as much as when retrofitting

## Blocked on a business answer

**Owner disclosure level** — how much does an owner on a `fixed_monthly` agreement see? The design
shows their car's gross rental revenue and rental days, but not company margin. Confirm before
building `OwnerStatementService`'s presentation.

## Deliverables

### Owner portal (REQ-19, ADV-08)
- [ ] `MyCarsResource`, `MyInstallmentsResource`, `MyPaymentsResource`, `MyDocumentsResource`,
      `MyStatementsPage`, `MyProfilePage` — **purpose-built, read-only, explicit column allowlists**
- [ ] Owner widgets: fleet status, next instalment due, received YTD, monthly receipts chart
- [ ] Monthly statement PDF via `OwnerStatementService`
- [ ] Owner invitation flow linking a `car_owner` user to `car_owners.user_id`

### Client portal (ADV-09)
- [ ] `MyBookingsResource`, `MyContractsResource` (including the **signature landing page**),
      `MyInvoicesResource`, `MyFinesResource`, `MyProfilePage`
- [ ] Client widgets: active rental with return countdown, outstanding balance, deposit status,
      next payment due

### ⚠ All four isolation layers — any one alone is a single point of failure
1. [ ] **Resource not registered on the panel.** No route exists. Strongest control.
2. [ ] **Purpose-built read-only resources**, never the admin resource re-registered with a filter —
       otherwise a column added to `cars` in a later phase leaks by default.
3. [ ] **`getEloquentQuery()` scoped** + a model global scope, so relation managers, widgets and
       exports inherit it. Scoping the table and forgetting the widgets is the classic leak.
4. [ ] **Policies re-check ownership** independently, so `/owner/installments/9999` returns 403, not
       404-by-luck.

Plus: private-disk files via policy-checked signed URLs; global search **disabled** on portals
(a known way to enumerate hidden records); rate limiting on portal login, OTP and PDF download;
impersonation `super_admin`-only, logged and banner-visible.

### Audit (ADV-03)
- [ ] Activitylog on every model that matters, with old/new values
- [ ] `ActivityLogResource` — view-only, filterable by user / model / date
- [ ] **Rejected ledger-mutation attempts logged**
- [ ] `branch_id` on log rows

### Backups (ADV-04)
- [ ] Scheduled nightly database + weekly full with media; off-site destination; retention policy;
      failure alerts to the manager
- [ ] **`BackupService::verifyLatest()`** — restore into a scratch database and assert row counts, on
      a schedule. A backup that has never been restored is only a hypothesis.

### Multi-branch enforcement (ADV-06)
- [ ] Flip `config('mcars.branches.enabled')` to true; add `BranchScope`
- [ ] `BranchContext` singleton + `ResolveBranchContext` middleware (session-scoped, re-validated
      against `accessibleBranchIds()` **every request**, so a stale session cannot outlive a revoked
      grant)
- [ ] `BranchSwitcher` Livewire component in the topbar via a render hook — **not** Filament native
      tenancy, which puts the tenant in the URL (breaking existing links) and has no "all branches"
      mode
- [ ] Per-branch cash boxes, sequences and reports; cross-branch booking (pickup A, return B);
      consolidated vs per-branch dashboards
- [ ] **Inter-branch clearing account 2600** — a transfer posts two rows sharing a `group_uuid`, one
      per branch, or both branches' cash balances are wrong. Company-wide reports exclude 2600.

### ⚠ Three things that break silently
- **`BranchScope` must be disabled on the owner and client panels.** Portal users have no branch
  context; depending on the fallback their records vanish or all appear.
- **The scope must not apply to queued jobs or console commands** — no session exists, so it resolves
  to nothing and produces wrong data with no error. Fail toward *unscoped* in background contexts and
  *denied* in HTTP contexts.
- **Branch B's "due for return today" widget queries `return_branch_id`, not `branch_id`**, or staff
  never see cars physically arriving at their counter.

## Tests — permanent regression suite

```
owner A cannot list owner B's installments
owner A cannot GET /owner/installments/{B's id}            → 403
owner A cannot download B's agreement PDF                   → 403
owner panel exposes no route matching /expenses|/transactions|/customers
owner with cars at two branches sees both                   ← the portal-scope trap
client A cannot view client B's contract                    → 403
client sees no expense, cost or margin field in any response
portal widget queries are scoped (assert the predicate in generated SQL)
dashboard cache primed as Branch A returns different figures for Branch B
inter-branch transfer leaves both branches balanced; 2600 nets to zero
company-wide revenue excludes inter-branch clearing
contract numbers never collide across branches
Branch B due-returns includes a car picked up at A, returning to B
audit log captures create/update/delete with old and new values
a restore from backup produces a working database
an unconfigured staff account sees nothing, not everything
```

## Definition of done

Log in as a car owner and see only their cars and money; log in as a customer and sign a contract;
show an audit trail entry with before/after values; restore a backup and verify row counts.
Gates green.
