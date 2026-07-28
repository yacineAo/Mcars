# 07 — Enum Catalogue

Every enum in the system, so phases do not invent divergent values for the same concept.

**Implementation.** PHP 8 backed enums (`string`), stored as `varchar` with a DB `check` constraint.
Not native Postgres enums — altering them requires locking DDL, and this list will change.
Each implements `HasLabel`, `HasColor` and `HasIcon` for Filament, with labels translated in
`lang/{ar,fr,en}/enums.php`.

```php
enum CarStatus: string implements HasLabel, HasColor, HasIcon
{
    case Available = 'available';
    // ...
    public function getLabel(): string { return __("enums.car_status.{$this->value}"); }
}
```

---

## Fleet

### `CarStatus`
| Value | Label | Colour | Notes |
|---|---|---|---|
| `available` | Available | success | Bookable now |
| `reserved` | Reserved | info | Confirmed booking not yet picked up |
| `rented` | Rented | warning | Out with a customer |
| `maintenance` | In Maintenance | danger | Has an open `car_blocks` row |
| `out_of_service` | Out of Service | gray | Accident, immobilised, awaiting parts |
| `sold` | Sold | gray | Terminal |
| `returned_to_owner` | Returned to Owner | gray | Terminal, third-party cars |

Transitions are enforced by `FleetStatusService`. `sold` and `returned_to_owner` are terminal; a car
with an active booking cannot enter either.

### `OwnershipType`
`company_owned` · `third_party`

### `AgreementModel`
`fixed_monthly` · `revenue_share` · `hybrid`

### `AgreementStatus`
`draft` · `active` · `suspended` · `ended`

### `FuelType`
`petrol` · `diesel` · `gpl` · `hybrid` · `electric`
*(GPL is common in Algeria and is a distinct case, not a variant of petrol.)*

### `TransmissionType`
`manual` · `automatic`

### `BodyType`
`sedan` · `hatchback` · `suv` · `crossover` · `pickup` · `van` · `minibus` · `utility` · `coupe`

### `CarDocumentType`
`insurance` · `registration_card` · `technical_inspection` · `road_tax_vignette` ·
`ownership_title` · `purchase_invoice` · `gps_subscription` · `other`

### `DocumentStatus` *(computed accessor, never stored)*
`valid` · `expiring_soon` · `expired` · `missing`

### `MaintenanceType`
`oil_change` · `tire_change` · `brakes` · `filters` · `general_service` · `repair` ·
`body_work` · `battery` · `diagnostics` · `cleaning` · `other`

### `MaintenanceStatus`
`scheduled` · `in_progress` · `completed` · `cancelled`

### `VendorType`
`garage` · `insurance` · `parts` · `fuel_station` · `towing` · `other`

---

## CRM

### `CustomerType`
`individual` · `company`

### `CustomerDocumentType`
`national_id` · `driving_license` · `passport` · `residence_proof` · `company_register` · `other`

### `CustomerSource`
`walk_in` · `referral` · `website` · `facebook` · `instagram` · `partner` · `other`

---

## Booking & Contracts

### `BookingStatus`
| Value | Label | Colour | In the `EXCLUDE` constraint? |
|---|---|---|:--:|
| `draft` | Draft | gray | no |
| `pending` | Pending | warning | no |
| `confirmed` | Confirmed | info | **yes** |
| `active` | Active (picked up) | success | **yes** |
| `completed` | Completed | gray | no |
| `cancelled` | Cancelled | danger | no |
| `no_show` | No Show | danger | no |
| `overdue` | Overdue | danger | **yes** |

`draft` and `pending` are excluded from the overlap constraint so multiple quotes can be prepared for
the same car and period; only one can be confirmed.

### `BlockReason`
`maintenance` · `owner_use` · `inter_branch_transfer` · `insurance_claim` · `administrative` · `other`

### `ExtraPricingUnit`
`per_day` · `per_rental` · `per_km`

### `ConditionReportType`
`checkout` · `checkin`

### `FuelLevel`
`empty` · `quarter` · `half` · `three_quarters` · `full`

### `ContractStatus`
`draft` · `awaiting_signature` · `signed` · `active` · `closed` · `cancelled` · `amended`

### `SignerRole`
`customer` · `additional_driver` · `company_representative` · `guarantor`

### `SignatureMethod`
`drawn` · `otp` · `uploaded_scan` · `in_person_paper`

### `InsuranceType`
`basic` · `full`

### `Locale`
`ar` · `fr` · `en`

---

## Finance

### `AccountType`
`asset` · `liability` · `equity` · `revenue` · `expense`

### `NormalBalance`
`debit` · `credit`

### `TransactionType`
The semantic label on a ledger row. Purely for reporting and filtering — the accounts, not this field,
determine the accounting effect.

`rental_revenue` · `extras_revenue` · `late_fee` · `excess_mileage` · `fuel_recharge` ·
`damage_recovery` · `cleaning_fee` · `fine_recharge` · `deposit_forfeited` ·
`customer_payment` · `customer_refund` · `overpayment` ·
`deposit_held` · `deposit_refunded` · `deposit_deducted` ·
`owner_rent_accrued` · `owner_payment` ·
`expense` · `expense_payment` · `maintenance` · `insurance` · `fuel` · `depreciation` ·
`fine_received` · `fine_paid` · `fine_recovered` · `fine_written_off` ·
`salary_accrued` · `salary_paid` · `commission` · `employee_advance` · `advance_recovered` ·
`cash_transfer` · `cash_deposit_to_bank` · `opening_float` · `cash_over` · `cash_short` ·
`capital` · `drawings` · `tax` · `bank_charge` · `reversal` · `adjustment`

### `PaymentMethod` — REQ-07
| Value | Label | Cash-equivalent account |
|---|---|---|
| `cash` | Cash | 1010 Main Cash Box |
| `bank_transfer` | Bank Transfer (Virement) | 1020 Bank |
| `ccp` | CCP | 1030 CCP |
| `card` | Card (TPE) | 1050 POS Clearing → 1020 on settlement |
| `baridimob` | BaridiMob | 1040 BaridiMob |
| `cheque` | Cheque | 1020 Bank, on clearing |
| `compensation` | Offset / Compensation | no cash movement |

`compensation` covers netting — deducting a cost from an owner's rent, or applying a customer credit
balance — where no money physically moves. It must still be posted, or the two balances never clear.

### `PaymentDirection`
`inbound` · `outbound`

### `PaymentStatus`
`pending` · `cleared` · `bounced` · `cancelled` · `refunded`

### `PaymentScheduleStatus`
`pending` · `partially_paid` · `paid` · `overdue` · `waived` · `cancelled`

### `ExpenseStatus`
`draft` · `pending_approval` · `approved` · `paid` · `rejected`

### `FinancialAccountType`
`cash_box` · `bank` · `ccp` · `baridimob` · `card_terminal` · `safe`

### `CashSessionStatus`
`open` · `closed` · `reconciled` · `disputed`

### `InstallmentStatus`
`pending` · `partially_paid` · `paid` · `overdue` · `waived` · `cancelled`

### `DepositStatus`
`held` · `partially_refunded` · `refunded` · `forfeited` · `applied_to_charges`

### `DeductionReason`
| Value | Credits |
|---|---|
| `damage` | 4060 Damage Recovery |
| `missing_fuel` | 4050 Fuel Recharge |
| `late_return` | 4030 Late Return Fees |
| `traffic_fine` | 1120 Fines Receivable |
| `cleaning` | 4080 Cleaning Fees |
| `excess_mileage` | 4040 Excess Mileage |
| `missing_accessory` | 4060 Damage Recovery |
| `other` | 4060 Damage Recovery |

The credit account is a property of the reason — this mapping is what keeps `DepositService` from
needing a switch statement in three places.

---

## HR

### `EmployeeStatus`
`active` · `on_leave` · `suspended` · `terminated`

### `ContractType`
`cdi` · `cdd` · `trial` · `freelance`

### `SalaryType`
`monthly` · `daily` · `hourly`

### `PayrollRunStatus`
`draft` · `approved` · `paid` · `cancelled`

### `PayrollItemStatus`
`pending` · `approved` · `paid`

### `AdvanceStatus`
`outstanding` · `partially_recovered` · `recovered` · `written_off`

### `CommissionStatus`
`pending` · `approved` · `paid` · `cancelled`

---

## Operations

### `FineType`
`speeding` · `parking` · `red_light` · `no_seatbelt` · `phone_use` · `wrong_way` · `toll` ·
`administrative` · `other`

### `FineLiability`
`customer` · `company` · `owner` · `pending_review`

Default is `pending_review`. `FineLiabilityService` proposes; a human confirms (ADR-011).

### `FineStatus`
`new` · `pending_review` · `assigned_to_customer` · `disputed` · `paid_by_company` ·
`recovered_from_customer` · `deducted_from_deposit` · `written_off` · `closed`

### `NotificationChannel`
`mail` · `whatsapp` · `sms` · `database` · `push`

### `NotificationStatus`
`queued` · `sending` · `sent` · `delivered` · `read` · `failed` · `cancelled`

### `AlertType` — REQ-17
`booking_return_due` · `booking_overdue` · `payment_overdue` · `installment_due` ·
`document_expiring` · `license_expiring` · `maintenance_due` · `recurring_expense_due` ·
`cash_variance` · `backup_failed`

---

## Access & System

### `UserRole` *(seeded Spatie roles, mirrored as an enum for type-safe checks)*
| Value | Panel |
|---|---|
| `super_admin` | admin |
| `manager` | admin |
| `accountant` | admin |
| `receptionist` | admin |
| `maintenance_officer` | admin |
| `supervisor` | admin |
| `car_owner` | owner |
| `client` | client |

### `SequenceKey`
`contract` · `booking` · `transaction` · `payment` · `expense` · `invoice`

### `ExportFormat`
`pdf` · `xlsx` · `csv`

---

## Naming rules for new enums

1. Values are `snake_case`, singular, and describe a **state or kind**, never an action
   (`refunded`, not `refund`).
2. Enum class names are singular PascalCase and suffixed by their concept
   (`BookingStatus`, not `BookingStatuses`).
3. **Never renumber or rename a value after go-live** — it is persisted in every historical row.
   Deprecate by hiding it from the form while keeping the case.
4. Every status enum needs its terminal states identified and its illegal transitions guarded by a
   service.
5. Add new values here in the same session that adds them to the code, or the next phase will invent a
   parallel set.
