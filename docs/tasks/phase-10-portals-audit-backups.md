# Phase 10 — Audit, Backups, Multi-Branch

**Status: ✅ Done** · Depends on: all previous · Closes: **ADV-03**, **ADV-04**, **ADV-06**

The system becomes operationally safe to run.

> **Scope reduced.** This phase was originally *Portals, Audit, Backups, Multi-Branch* and carried
> roughly half its weight in the owner and client portals. The business confirmed the system is
> office-only, so both portals — and the four-layer isolation model, its permanent regression suite,
> and the owner-disclosure question that blocked it — were cut. REQ-19, ADV-08 and ADV-09 are
> withdrawn; see [ADR-007](../06-design-decisions.md).

## Read first
[`../08-multi-branch-retrofit.md`](../08-multi-branch-retrofit.md) §3–4 — the switcher design and the
hazards, which apply when *switching enforcement on* just as much as when retrofitting

## Deliverables

### ~~Owner portal~~ / ~~Client portal~~ / ~~isolation layers~~ — **withdrawn**

Removed with the portals. Owner statements are still produced, but by staff inside the admin panel
via `OwnerStatementService` — a report the office runs, not a page an owner logs in to.

`car_owners.user_id` and `customers.user_id` remain in the schema, unused, as the seam if a portal is
ever wanted. Reintroducing one means restoring the whole isolation model, not just adding a panel.

### Audit (ADV-03)
- [x] Activitylog on every model that matters, with old/new values
- [x] `ActivityLogResource` — view-only, filterable by user / model / date
- [x] **Rejected ledger-mutation attempts logged**
- [x] `branch_id` on log rows

### Backups (ADV-04)
- [x] Scheduled nightly database + weekly full with media; off-site destination; retention policy;
      failure alerts to the manager
- [x] **`BackupService::verifyLatest()`** — restore into a scratch database and assert row counts, on
      a schedule

### Multi-branch enforcement (ADV-06)
- [x] Flip `config('mcars.branches.enabled')` to true; add `BranchScope`
- [x] `BranchContext` singleton + `ResolveBranchContext` middleware (session-scoped, re-validated
      against `accessibleBranchIds()` **every request**, so a stale session cannot outlive a revoked
      grant)
- [x] `BranchSwitcher` Livewire component in the topbar via a render hook — **not** Filament native
      tenancy, which puts the tenant in the URL (breaking existing links) and has no "all branches"
      mode
- [x] Per-branch cash boxes, sequences and reports; cross-branch booking (pickup A, return B);
      consolidated vs per-branch dashboards
- [x] **Inter-branch clearing account 2600** — a transfer posts two rows sharing a `group_uuid`, one
      per branch, or both branches' cash balances are wrong. Company-wide reports exclude 2600.

### ⚠ Two things that break silently
- **The scope must not apply to queued jobs or console commands** — no session exists, so it resolves
  to nothing and produces wrong data with no error. Fail toward *unscoped* in background contexts and
  *denied* in HTTP contexts.
- **Branch B's "due for return today" widget queries `return_branch_id`, not `branch_id`**, or staff
  never see cars physically arriving at their counter.

## Tests — permanent regression suite

```
/owner and /client resolve to 404 — the portals are gone and must stay gone
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
