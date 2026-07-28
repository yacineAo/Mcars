<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AgreementModel;
use App\Enums\BookingStatus;
use App\Enums\CarDocumentType;
use App\Enums\CarStatus;
use App\Enums\ConditionReportType;
use App\Enums\CustomerType;
use App\Enums\DepositStatus;
use App\Enums\ExpenseStatus;
use App\Enums\FineLiability;
use App\Enums\FineType;
use App\Enums\FuelLevel;
use App\Enums\InstallmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\OwnershipType;
use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarCategory;
use App\Models\CarDocument;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\ChartOfAccount;
use App\Models\ConditionReport;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Extra;
use App\Models\FinancialAccount;
use App\Models\Fine;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\ExpensePoster;
use App\Services\Accounting\TransactionDraft;
use App\Services\Booking\BookingService;
use App\Services\Booking\ContractService;
use App\Services\CashRegisterService;
use App\Services\Payment\DepositService;
use App\Services\Payment\FineLiabilityService;
use App\Services\Payment\PaymentService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A realistic working dataset, so the system can be looked at rather than imagined.
 *
 * **Everything financial goes through the services**, exactly as the admin panel
 * does. Creating bookings and payments straight from factories would fill the
 * tables and leave the ledger empty — every dashboard figure would read zero, and
 * the demo would misrepresent the system as broken. Revenue therefore comes from
 * BookingService::checkOut(), payments from PaymentService, deposits from
 * DepositService, and so on.
 *
 * Dates are spread over the previous ~4 months because postings take their
 * accounting date from the source document (`actual_pickup_at`, `incurred_on`).
 * Backdating the documents is what gives the 12-month charts something to draw.
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Idempotency is deliberately *not* attempted — run it once on a fresh database:
 *
 *     php artisan migrate:fresh --seed && php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private const int CARS = 12;

    private const int CUSTOMERS = 22;

    /** How far back the history runs. Enough for the 12-month charts to be non-trivial. */
    private const int HISTORY_DAYS = 120;

    private Branch $branch;

    private User $manager;

    private User $receptionist;

    private User $accountant;

    private FinancialAccount $cashBox;

    private FinancialAccount $bank;

    /** @var list<Car> */
    private array $cars = [];

    /** @var list<Customer> */
    private array $customers = [];

    /** @var list<Employee> */
    private array $employees = [];

    private CarbonImmutable $today;

    public function run(): void
    {
        // Demo data invents customers, contracts and ledger entries. Dropping that
        // into a live database would corrupt real books.
        if (App::environment('production')) {
            throw new RuntimeException('DemoDataSeeder must never run in production.');
        }

        $this->today = CarbonImmutable::now()->startOfDay();

        $this->command->info('Seeding demo data — this takes a moment, everything posts through the services.');

        $this->foundation();
        $this->staff();
        $this->openingCapital();
        $this->catalogue();
        $this->owners();
        $this->fleet();
        $this->maintenance();
        $this->people();
        $this->rentalHistory();
        $this->ownerPayouts();
        $this->runningCosts();
        $this->trafficFines();
        $this->payroll();
        $this->tillShift();

        $this->summarise();
    }

    // -----------------------------------------------------------------------
    // Foundation
    // -----------------------------------------------------------------------

    private function foundation(): void
    {
        foreach ([
            RolePermissionSeeder::class,
            BranchSeeder::class,
            ChartOfAccountSeeder::class,
            ExpenseCategorySeeder::class,
            FinancialAccountSeeder::class,
            CarCategorySeeder::class,
            VendorSeeder::class,
            AlertRuleSeeder::class,
        ] as $seeder) {
            $this->callSilentlyIfEmpty($seeder);
        }

        $this->branch = Branch::query()->where('is_default', true)->firstOrFail();

        $this->cashBox = FinancialAccount::query()->where('type', 'cash_box')->firstOrFail();
        $this->bank = FinancialAccount::query()->where('type', 'bank')->first() ?? $this->cashBox;
    }

    private function note(string $message): void
    {
        $this->command->line($message);
    }

    private function callSilentlyIfEmpty(string $seeder): void
    {
        try {
            $this->callSilent($seeder);
        } catch (Throwable) {
            // Foundation seeders are idempotent by design; a unique-constraint clash
            // just means this one already ran.
        }
    }

    private function staff(): void
    {
        $people = [
            [UserRole::Manager, 'Karim Belkacem', 'manager@mcars.dz'],
            [UserRole::Accountant, 'Nadia Hamdi', 'accountant@mcars.dz'],
            [UserRole::Receptionist, 'Sofiane Meziane', 'reception@mcars.dz'],
            [UserRole::MaintenanceOfficer, 'Rachid Amrani', 'workshop@mcars.dz'],
            [UserRole::Supervisor, 'Leila Bouzid', 'supervisor@mcars.dz'],
        ];

        foreach ($people as [$role, $name, $email]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => bcrypt('password'),
                    'branch_id' => $this->branch->id,
                    'phone' => '05'.fake()->numerify('########'),
                    'locale' => 'fr',
                    'is_active' => true,
                ],
            );

            if (! $user->hasRole($role->value)) {
                $user->assignRole($role->value);
            }

            match ($role) {
                UserRole::Manager => $this->manager = $user,
                UserRole::Accountant => $this->accountant = $user,
                UserRole::Receptionist => $this->receptionist = $user,
                default => null,
            };
        }

        $this->note('  staff: 5 users, one per role (password: "password")');
    }

    /**
     * Fund the business before it starts trading.
     *
     * Without this the demo runs at negative cash: most revenue sits in
     * receivables while wages, owner rent and running costs go out in real money.
     * A rental company starts with capital, and a cash box cannot pay out money it
     * never received.
     */
    private function openingCapital(): void
    {
        $accounting = app(AccountingService::class);

        $bank = ChartOfAccount::query()->where('code', '1020')->firstOrFail();
        $cash = ChartOfAccount::query()->where('code', '1010')->firstOrFail();
        $capital = ChartOfAccount::query()->where('code', '3000')->firstOrFail();
        $on = new \DateTimeImmutable($this->today->subDays(self::HISTORY_DAYS + 5)->toDateString());

        // E70 — capital injected by the owners.
        $accounting->postMany(
            new TransactionDraft(
                debitAccountId: $bank->id,
                creditAccountId: $capital->id,
                amount: '4000000.00',
                type: TransactionType::Capital,
                occurredOn: $on,
                description: 'Apport en capital — ouverture',
                branchId: $this->branch->id,
                createdById: $this->manager->id,
            ),
            new TransactionDraft(
                debitAccountId: $cash->id,
                creditAccountId: $bank->id,
                amount: '300000.00',
                type: TransactionType::CashTransfer,
                occurredOn: $on,
                description: 'Alimentation de la caisse',
                branchId: $this->branch->id,
                createdById: $this->manager->id,
            ),
        );

        $this->note('  capital: 4 000 000 DZD injected, 300 000 moved to the till');
    }

    private function catalogue(): void
    {
        $extrasRevenue = ChartOfAccount::query()->where('code', '4020')->firstOrFail();

        foreach ([
            ['GPS', 'GPS', 'per_day', 500],
            ['Child seat', 'SEAT', 'per_day', 400],
            ['Additional driver', 'DRV2', 'per_rental', 2000],
            ['Airport delivery', 'DELIV', 'per_rental', 3000],
            ['Full insurance', 'INSUR', 'per_day', 1200],
        ] as [$name, $code, $unit, $price]) {
            Extra::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'pricing_unit' => $unit,
                    'unit_price' => $price,
                    'ledger_account_id' => $extrasRevenue->id,
                    'is_active' => true,
                ],
            );
        }

        foreach (['fr' => 'Contrat de location', 'ar' => 'عقد كراء', 'en' => 'Rental agreement'] as $locale => $name) {
            ContractTemplate::query()->firstOrCreate(
                ['locale' => $locale, 'name' => $name],
                [
                    'branch_id' => $this->branch->id,
                    'body' => "{$name}\n\n{{customer_name}} — {{car_description}}\n{{pickup_at}} → {{expected_return_at}}\nTotal: {{total_amount}} DZD",
                    'terms_version' => '1.0',
                    'is_active' => true,
                    'is_default' => $locale === 'fr',
                ],
            );
        }

        $this->note('  catalogue: 5 extras, 3 contract templates');
    }

    // -----------------------------------------------------------------------
    // Fleet
    // -----------------------------------------------------------------------

    private function owners(): void
    {
        $owners = [
            ['individual', 'Mourad', 'Cherifi', null, AgreementModel::FixedMonthly, 65000, null],
            ['individual', 'Yacine', 'Boudiaf', null, AgreementModel::FixedMonthly, 80000, null],
            // car_owners.first_name/last_name are NOT NULL even for a company, so a
            // company owner carries its contact person's name alongside the trading name.
            ['company', 'Djamel', 'Ould Ali', 'SARL Transloc', AgreementModel::RevenueShare, null, 35],
        ];

        foreach ($owners as [$type, $first, $last, $company, $model, $rent, $share]) {
            $owner = CarOwner::query()->create([
                'branch_id' => $this->branch->id,
                'type' => $type,
                'first_name' => $first,
                'last_name' => $last,
                'company_name' => $company,
                'phone' => '05'.fake()->numerify('########'),
                'email' => fake()->safeEmail(),
                'wilaya' => fake()->randomElement(['Alger', 'Oran', 'Blida', 'Constantine']),
                'ccp_account' => fake()->numerify('##########'),
                'is_active' => true,
            ]);

            $this->ownerAgreements[] = ['owner' => $owner, 'model' => $model, 'rent' => $rent, 'share' => $share];
        }

        $this->note('  owners: 3 car owners with agreements');
    }

    /** @var list<array{owner: CarOwner, model: AgreementModel, rent: int|null, share: int|null}> */
    private array $ownerAgreements = [];

    private function fleet(): void
    {
        $categories = CarCategory::query()->get();

        $models = [
            ['Renault', 'Clio 5', 4500], ['Dacia', 'Logan', 4000], ['Peugeot', '208', 5000],
            ['Hyundai', 'i10', 3500], ['Toyota', 'Corolla', 7000], ['Volkswagen', 'Golf 8', 8000],
            ['Dacia', 'Duster', 7500], ['Renault', 'Symbol', 4200], ['Kia', 'Picanto', 3400],
            ['Seat', 'Ibiza', 5200], ['Skoda', 'Octavia', 8500], ['Fiat', 'Doblo', 6000],
        ];

        foreach ($models as $i => [$brand, $model, $rate]) {
            // The last three are rented from third-party owners; the rest are ours.
            $thirdParty = $i >= self::CARS - 3;
            $agreement = $thirdParty ? $this->ownerAgreements[$i - (self::CARS - 3)] : null;

            $car = Car::query()->create([
                'branch_id' => $this->branch->id,
                'car_category_id' => $categories->random()->id,
                'car_owner_id' => $agreement['owner']->id ?? null,
                'ownership_type' => $thirdParty ? OwnershipType::ThirdParty : OwnershipType::CompanyOwned,
                'brand' => $brand,
                'model' => $model,
                'year' => fake()->numberBetween(2019, 2025),
                'color' => fake()->randomElement(['Blanc', 'Gris', 'Noir', 'Bleu', 'Rouge']),
                'chassis_number' => strtoupper(fake()->bothify('VF1??????????????')),
                'registration_number' => fake()->numerify('#####').'-'.fake()->numerify('###').'-16',
                'status' => CarStatus::Available,
                'daily_rate' => $rate,
                'weekly_rate' => $rate * 6,
                'monthly_rate' => $rate * 22,
                'security_deposit_amount' => $rate * 6,
                'mileage_limit_per_day' => 200,
                'extra_km_price' => 25,
                'odometer' => fake()->numberBetween(8000, 120000),
                'fuel_level' => FuelLevel::Full,
                'insurance_expiry_date' => $this->today->addDays(fake()->numberBetween(10, 300)),
                'technical_inspection_expiry_date' => $this->today->addDays(fake()->numberBetween(15, 350)),
                'is_active' => true,
            ]);

            $this->cars[] = $car;

            if ($agreement !== null) {
                CarOwnershipAgreement::query()->create([
                    'branch_id' => $this->branch->id,
                    'car_id' => $car->id,
                    'car_owner_id' => $agreement['owner']->id,
                    'model' => $agreement['model'],
                    'status' => 'active',
                    'start_date' => $this->today->subMonths(8),
                    'monthly_rent_amount' => $agreement['rent'],
                    'share_percentage' => $agreement['share'],
                    'payment_day_of_month' => 5,
                ]);
            }

            // Documents — a couple deliberately expire soon so the alerts have
            // something real to find.
            $soon = $i < 3;

            CarDocument::query()->create([
                'car_id' => $car->id,
                'type' => CarDocumentType::Insurance,
                'number' => strtoupper(fake()->bothify('POL-######')),
                'issuer' => fake()->randomElement(['SAA', 'CAAR', 'CAAT', 'Alliance Assurances']),
                'issue_date' => $this->today->subYear(),
                'expiry_date' => $soon ? $this->today->addDays(fake()->numberBetween(5, 25)) : $car->insurance_expiry_date,
                'cost' => fake()->numberBetween(35000, 60000),
                'reminder_days_before' => 30,
            ]);

            CarDocument::query()->create([
                'car_id' => $car->id,
                'type' => CarDocumentType::TechnicalInspection,
                'number' => strtoupper(fake()->bothify('CT-######')),
                'issue_date' => $this->today->subMonths(6),
                'expiry_date' => $car->technical_inspection_expiry_date,
                'cost' => 3000,
                'reminder_days_before' => 30,
            ]);
        }

        // One car off the road, so the fleet gauge is not uniformly green.
        $this->cars[count($this->cars) - 1]->update(['status' => CarStatus::Maintenance]);

        $this->note('  fleet: '.self::CARS.' cars (3 third-party, 1 in maintenance), 24 documents');
    }

    private function maintenance(): void
    {
        $garage = Vendor::query()->first();

        foreach ($this->cars as $i => $car) {
            MaintenanceSchedule::query()->create([
                'car_id' => $car->id,
                'task_type' => MaintenanceType::OilChange,
                'interval_km' => 10000,
                'interval_days' => 180,
                'last_done_at' => $this->today->subDays(fake()->numberBetween(60, 170)),
                'last_done_odometer' => max(0, $car->odometer - fake()->numberBetween(3000, 9000)),
                // A few fall due inside the alert window on purpose.
                'next_due_at' => $this->today->addDays($i < 3 ? fake()->numberBetween(2, 6) : fake()->numberBetween(40, 150)),
                'next_due_odometer' => $car->odometer + ($i < 3 ? 400 : fake()->numberBetween(4000, 9000)),
                'alert_km_before' => 1000,
                'alert_days_before' => 14,
                'is_active' => true,
            ]);

            if ($i % 3 === 0) {
                MaintenanceLog::query()->create([
                    'car_id' => $car->id,
                    'branch_id' => $this->branch->id,
                    'vendor_id' => $garage?->id,
                    'type' => fake()->randomElement([MaintenanceType::OilChange, MaintenanceType::Brakes, MaintenanceType::TireChange]),
                    'status' => MaintenanceStatus::Completed,
                    'scheduled_for' => $this->today->subDays(fake()->numberBetween(20, 90)),
                    'completed_at' => $this->today->subDays(fake()->numberBetween(5, 19)),
                    'odometer_at_service' => $car->odometer,
                    'cost_parts' => $parts = fake()->numberBetween(4000, 20000),
                    'cost_labour' => $labour = fake()->numberBetween(2000, 8000),
                    'total_cost' => $parts + $labour,
                    'description' => 'Entretien périodique',
                ]);
            }
        }

        $this->note('  maintenance: '.self::CARS.' schedules, '.(int) ceil(self::CARS / 3).' completed jobs');
    }

    private function people(): void
    {
        $wilayas = ['Alger', 'Oran', 'Constantine', 'Blida', 'Sétif', 'Annaba', 'Tizi Ouzou', 'Béjaïa'];

        for ($i = 0; $i < self::CUSTOMERS; $i++) {
            $company = $i % 7 === 0;
            // A few licences expire inside the alert window.
            $licenceExpiry = $i < 2
                ? $this->today->addDays(fake()->numberBetween(5, 25))
                : $this->today->addYears(fake()->numberBetween(1, 6));

            $this->customers[] = Customer::query()->create([
                'branch_id' => $this->branch->id,
                'type' => $company ? CustomerType::Company : CustomerType::Individual,
                'first_name' => $company ? null : fake()->firstName(),
                'last_name' => $company ? null : fake()->lastName(),
                'company_name' => $company ? 'SARL '.fake()->lastName() : null,
                'trade_register' => $company ? fake()->numerify('##/##-#######') : null,
                'national_id' => fake()->numerify('##############'),
                'date_of_birth' => fake()->dateTimeBetween('-60 years', '-21 years'),
                'phone' => '0'.fake()->randomElement([5, 6, 7]).fake()->numerify('########'),
                'email' => fake()->safeEmail(),
                'address' => fake()->streetAddress(),
                'wilaya' => fake()->randomElement($wilayas),
                'driving_license_number' => strtoupper(fake()->bothify('P-######')),
                'license_category' => 'B',
                'license_issue_date' => $this->today->subYears(fake()->numberBetween(2, 20)),
                'license_expiry_date' => $licenceExpiry,
                'source' => fake()->randomElement(['walk_in', 'referral', 'website', 'facebook', 'instagram']),
                'rating' => fake()->numberBetween(3, 5),
                'is_active' => true,
            ]);
        }

        foreach ([
            ['Sofiane', 'Meziane', 'Réceptionniste', 45000],
            ['Rachid', 'Amrani', 'Chef d\'atelier', 60000],
            ['Nadia', 'Hamdi', 'Comptable', 70000],
            ['Amine', 'Larbi', 'Chauffeur', 40000],
            ['Fatima', 'Zerrouki', 'Agent commercial', 48000],
        ] as $i => [$first, $last, $title, $salary]) {
            $this->employees[] = Employee::query()->create([
                'branch_id' => $this->branch->id,
                'employee_number' => 'EMP-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'last_name' => $last,
                'national_id' => fake()->numerify('##############'),
                'phone' => '05'.fake()->numerify('########'),
                'job_title' => $title,
                'hire_date' => $this->today->subMonths(fake()->numberBetween(6, 48)),
                'contract_type' => 'cdi',
                'salary_type' => 'monthly',
                'base_salary' => $salary,
                'ccp_account' => fake()->numerify('##########'),
                'status' => 'active',
            ]);
        }

        $this->note('  people: '.self::CUSTOMERS.' customers, 5 employees');
    }

    // -----------------------------------------------------------------------
    // The money story — everything below posts to the ledger
    // -----------------------------------------------------------------------

    private function rentalHistory(): void
    {
        $bookings = app(BookingService::class);
        $payments = app(PaymentService::class);
        $deposits = app(DepositService::class);
        $contracts = app(ContractService::class);

        $completed = 0;
        $active = 0;
        $cursor = [];

        // Walk each car forward through time so no two rentals of the same car
        // overlap — the EXCLUDE constraint would (correctly) refuse them.
        foreach ($this->cars as $index => $car) {
            if ($car->status === CarStatus::Maintenance) {
                continue;
            }

            $cursor[$car->id] = $this->today->subDays(self::HISTORY_DAYS);

            for ($n = 0; $n < fake()->numberBetween(6, 10); $n++) {
                $start = $cursor[$car->id]->addDays(fake()->numberBetween(1, 4));
                $days = fake()->numberBetween(2, 9);
                $end = $start->addDays($days);

                if ($end->greaterThan($this->today->subDays(3))) {
                    break;
                }

                $booking = $this->makeBooking($car, $start, $end, $days);

                $bookings->confirm($booking, $this->receptionist);
                $contracts->generate($booking->fresh(), null, 'fr');

                $bookings->checkOut($booking->fresh(), [
                    'actual_pickup_at' => $start,
                    'odometer_out' => $car->odometer,
                    'fuel_level_out' => FuelLevel::Full->value,
                ], $this->receptionist);

                $this->holdDeposit($deposits, $booking->fresh(), $start);

                // Return, sometimes with a check-in report that drives closeout charges.
                $this->conditionReport($booking, ConditionReportType::Checkin, $end, $car->odometer + $days * fake()->numberBetween(80, 260));

                $bookings->checkInWithCharges($booking->fresh(), [
                    'actual_return_at' => $end,
                    'odometer_in' => $car->odometer + $days * fake()->numberBetween(80, 260),
                    'fuel_level_in' => fake()->randomElement([FuelLevel::Full, FuelLevel::ThreeQuarters])->value,
                ], $this->receptionist);

                $this->settle($payments, $deposits, $booking->fresh(), $end);

                $cursor[$car->id] = $end->addDays(1);
                $completed++;
            }

            // Three cars are out right now.
            if ($active < 3 && $index % 4 === 1) {
                $start = $this->today->subDays(fake()->numberBetween(1, 4));
                $days = fake()->numberBetween(4, 10);
                $booking = $this->makeBooking($car, $start, $start->addDays($days), $days);

                $bookings->confirm($booking, $this->receptionist);
                $contracts->generate($booking->fresh(), null, 'fr');
                $bookings->checkOut($booking->fresh(), [
                    'actual_pickup_at' => $start,
                    'odometer_out' => $car->odometer,
                    'fuel_level_out' => FuelLevel::Full->value,
                ], $this->receptionist);

                $this->holdDeposit($deposits, $booking->fresh(), $start);
                $active++;
            }
        }

        // Upcoming, and a couple that never happened — so every status is visible.
        $future = 0;
        foreach ($this->cars as $car) {
            if ($car->fresh()->status !== CarStatus::Available || $future >= 4) {
                continue;
            }

            $start = $this->today->addDays(fake()->numberBetween(2, 20));
            $days = fake()->numberBetween(2, 7);
            $booking = $this->makeBooking($car, $start, $start->addDays($days), $days);
            $bookings->confirm($booking, $this->receptionist);
            $future++;
        }

        foreach (array_slice($this->cars, 0, 2) as $car) {
            $start = $this->today->addDays(fake()->numberBetween(25, 40));
            $this->makeBooking($car, $start, $start->addDays(3), 3);  // stays Draft
        }

        foreach (array_slice($this->cars, 2, 2) as $car) {
            $start = $this->today->subDays(fake()->numberBetween(30, 60));
            $booking = $this->makeBooking($car, $start, $start->addDays(4), 4);
            $bookings->cancel($booking, 'Client annulé — imprévu familial', $this->receptionist);
        }

        $this->note("  rentals: {$completed} completed, {$active} out now, {$future} upcoming, 2 draft, 2 cancelled");
    }

    private function makeBooking(Car $car, CarbonImmutable $start, CarbonImmutable $end, int $days): Booking
    {
        $customer = fake()->randomElement($this->customers);
        $rate = (float) $car->daily_rate;
        $subtotal = $rate * $days;
        $extras = fake()->boolean(40) ? fake()->randomElement([500, 1200, 2000, 3000]) * $days : 0;
        $discount = fake()->boolean(15) ? round($subtotal * 0.1, 2) : 0;

        return Booking::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'BK-'.strtoupper(Str::random(8)),
            'branch_id' => $this->branch->id,
            'pickup_branch_id' => $this->branch->id,
            'return_branch_id' => $this->branch->id,
            'car_id' => $car->id,
            'customer_id' => $customer->id,
            'created_by_id' => $this->receptionist->id,
            'status' => BookingStatus::Draft,
            'pickup_at' => $start->setTime(9, 0),
            'expected_return_at' => $end->setTime(18, 0),
            'daily_rate' => $rate,
            'days_count' => $days,
            'subtotal' => $subtotal,
            'extras_total' => $extras,
            'discount_amount' => $discount,
            'total_amount' => $subtotal + $extras - $discount,
            'security_deposit_amount' => $car->security_deposit_amount,
            'with_driver' => false,
        ]);
    }

    private function conditionReport(Booking $booking, ConditionReportType $type, CarbonImmutable $at, int $odometer): void
    {
        ConditionReport::query()->create([
            'booking_id' => $booking->id,
            'type' => $type,
            'performed_at' => $at,
            'performed_by_id' => $this->receptionist->id,
            'odometer' => $odometer,
            'fuel_level' => FuelLevel::Full,
            'is_clean' => fake()->boolean(80),
            'damage_points' => [],
        ]);
    }

    private function holdDeposit(DepositService $deposits, Booking $booking, CarbonImmutable $at): Deposit
    {
        $deposit = Deposit::query()->create([
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'branch_id' => $this->branch->id,
            'amount' => $booking->security_deposit_amount,
            'method' => PaymentMethod::Cash,
            'financial_account_id' => $this->cashBox->id,
            'held_at' => $at,
            'status' => DepositStatus::Held,
        ]);

        // Dr cash / Cr Security Deposits Held — a liability, never revenue.
        $deposits->hold($deposit, $this->receptionist->id);

        return $deposit->fresh();
    }

    /** Take the money, then close out the deposit — refund, or deduct for damage. */
    private function settle(PaymentService $payments, DepositService $deposits, Booking $booking, CarbonImmutable $at): void
    {
        $method = fake()->randomElement([
            PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::Cash,
            PaymentMethod::BankTransfer, PaymentMethod::Ccp, PaymentMethod::BaridiMob,
        ]);

        // Most settle in full; some leave a balance so receivables ageing is real.
        $full = fake()->boolean(80);
        $amount = $full ? (float) $booking->total_amount : round((float) $booking->total_amount * 0.6, 2);

        $payment = Payment::query()->create([
            'reference' => 'PAY-'.strtoupper(Str::random(8)),
            'branch_id' => $this->branch->id,
            'direction' => 'inbound',
            'customer_id' => $booking->customer_id,
            'method' => $method,
            'amount' => $amount,
            'paid_at' => $at,
            'financial_account_id' => $method === PaymentMethod::Cash ? $this->cashBox->id : $this->bank->id,
            'status' => 'completed',
            'received_by_id' => $this->receptionist->id,
        ]);

        $payments->recordPayment($payment, $this->receptionist->id);

        $deposit = Deposit::query()->where('booking_id', $booking->id)->first();

        if ($deposit === null) {
            return;
        }

        if (fake()->boolean(20)) {
            // Damage found: part of the deposit becomes revenue, the rest is still owed.
            $deposits->deduct(
                $deposit,
                new DepositDeduction([
                    'deposit_id' => $deposit->id,
                    'reason' => fake()->randomElement(['damage', 'cleaning', 'fuel']),
                    'amount' => round((float) $deposit->amount * fake()->randomFloat(2, 0.1, 0.4), 2),
                    'description' => 'Constaté à la restitution',
                    'created_by_id' => $this->receptionist->id,
                ]),
                $this->receptionist->id,
            );
        }

        if (fake()->boolean(75)) {
            try {
                $deposits->refund($deposit->fresh(), null, $this->receptionist->id);
            } catch (Throwable) {
                // Fully consumed by deductions — nothing left to give back.
            }
        }
    }

    private function ownerPayouts(): void
    {
        $payments = app(PaymentService::class);
        $accrued = 0;

        foreach (CarOwnershipAgreement::query()->with('car')->get() as $agreement) {
            for ($m = 3; $m >= 1; $m--) {
                $month = $this->today->subMonths($m)->startOfMonth();

                $installment = OwnerInstallment::query()->create([
                    'car_ownership_agreement_id' => $agreement->id,
                    'car_owner_id' => $agreement->car_owner_id,
                    'car_id' => $agreement->car_id,
                    'branch_id' => $this->branch->id,
                    'sequence_number' => 4 - $m,
                    'total_installments' => 12,
                    'period_month' => $month,
                    'due_date' => $month->addDays(4),
                    'amount_due' => $agreement->monthly_rent_amount ?? 55000,
                    'status' => InstallmentStatus::Pending,
                ]);

                // Dr Owner Car Rent (stamped with car_id) / Cr Payable–Owners.
                // This is what keeps a third-party car's profit honest.
                $payments->accrueOwnerInstallment($installment, $this->accountant->id);
                $accrued++;

                // The two older months have been paid; the most recent is outstanding.
                if ($m > 1) {
                    $payment = Payment::query()->create([
                        'reference' => 'PAY-'.strtoupper(Str::random(8)),
                        'branch_id' => $this->branch->id,
                        'direction' => 'outbound',
                        'car_owner_id' => $agreement->car_owner_id,
                        'method' => PaymentMethod::BankTransfer,
                        'amount' => $installment->amount_due,
                        'paid_at' => $month->addDays(6),
                        'financial_account_id' => $this->bank->id,
                        'status' => 'completed',
                        'received_by_id' => $this->accountant->id,
                    ]);

                    $payments->recordPayment($payment, $this->accountant->id);
                    $installment->update(['status' => InstallmentStatus::Paid]);
                }
            }
        }

        $this->note("  owners: {$accrued} instalments accrued, most paid");
    }

    private function runningCosts(): void
    {
        $accounting = app(AccountingService::class);
        $poster = app(ExpensePoster::class);
        $vendor = Vendor::query()->first();

        $recipes = [
            ['fuel', 'Carburant', 3000, 9000, true],
            ['maintenance', 'Entretien', 8000, 35000, true],
            ['car-wash', 'Lavage', 800, 2500, true],
            ['office-rent', 'Loyer bureau', 60000, 60000, false],
            ['internet-telecom', 'Internet & téléphone', 6000, 9000, false],
            ['electricity-water', 'Électricité & eau', 5000, 14000, false],
            ['marketing-advertising', 'Publicité', 10000, 30000, false],
        ];

        $posted = 0;

        foreach ($recipes as [$slug, $label, $min, $max, $carRelated]) {
            $category = ExpenseCategory::query()->where('slug', $slug)->first();

            if ($category === null) {
                continue;
            }

            $occurrences = $carRelated ? 14 : 4;

            for ($i = 0; $i < $occurrences; $i++) {
                $on = $this->today->subDays(fake()->numberBetween(1, self::HISTORY_DAYS));
                $amount = fake()->numberBetween($min, $max);

                $expense = Expense::query()->create([
                    'reference' => 'EXP-'.strtoupper(Str::random(8)),
                    'branch_id' => $this->branch->id,
                    'expense_category_id' => $category->id,
                    // Car-related costs MUST carry a car, or per-car profit lies.
                    'car_id' => $carRelated ? fake()->randomElement($this->cars)->id : null,
                    'vendor_id' => $vendor?->id,
                    'amount' => $amount,
                    'total_amount' => $amount,
                    'incurred_on' => $on,
                    'description' => $label,
                    'status' => ExpenseStatus::Approved,
                    'approved_by_id' => $this->manager->id,
                    'approved_at' => $on,
                    'payment_method' => PaymentMethod::Cash,
                ]);

                $account = $amount > 20000 ? $this->bank : $this->cashBox;
                $accounting->post($poster->postImmediateExpense($expense, $account, $this->accountant->id));

                $expense->update([
                    'status' => ExpenseStatus::Paid,
                    'financial_account_id' => $account->id,
                    'paid_at' => $on,
                ]);

                $posted++;
            }
        }

        // A recurring bill that falls due shortly, so that alert has a subject.
        $rent = ExpenseCategory::query()->where('slug', 'office-rent')->first();

        if ($rent !== null) {
            Expense::query()->create([
                'reference' => 'EXP-'.strtoupper(Str::random(8)),
                'branch_id' => $this->branch->id,
                'expense_category_id' => $rent->id,
                'amount' => 60000,
                'total_amount' => 60000,
                'incurred_on' => $this->today,
                'description' => 'Loyer bureau — récurrent',
                'status' => ExpenseStatus::Approved,
                'is_recurring' => true,
                'recurrence_rule' => 'monthly',
                'next_occurrence_on' => $this->today->addDays(3),
            ]);
        }

        $this->note("  costs: {$posted} expenses paid and posted, 1 recurring bill due");
    }

    private function trafficFines(): void
    {
        $payments = app(PaymentService::class);
        $created = 0;

        foreach (array_slice($this->cars, 0, 5) as $i => $car) {
            $violation = $this->today->subDays(fake()->numberBetween(10, 80));

            // Match the fine to a rental that was running at the time, where one exists.
            $booking = Booking::query()
                ->where('car_id', $car->id)
                ->whereNotNull('actual_pickup_at')
                ->where('actual_pickup_at', '<=', $violation)
                ->where(fn ($q) => $q->where('actual_return_at', '>=', $violation)->orWhereNull('actual_return_at'))
                ->first();

            $amount = fake()->randomElement([2000, 2500, 5000, 8000]);

            $fine = Fine::query()->create([
                'reference' => 'FIN-'.strtoupper(Str::random(8)),
                'branch_id' => $this->branch->id,
                'car_id' => $car->id,
                'booking_id' => $booking?->id,
                'customer_id' => $booking?->customer_id,
                'type' => fake()->randomElement([FineType::Speeding, FineType::Parking]),
                'authority' => 'Sûreté Nationale',
                'notice_number' => fake()->numerify('PV-#######'),
                'violation_at' => $violation,
                'location' => fake()->randomElement(['RN5 Alger', 'Centre-ville Oran', 'Autoroute Est-Ouest']),
                'received_at' => $violation->addDays(fake()->numberBetween(5, 20)),
                'due_date' => $violation->addDays(45),
                'amount' => $amount,
                'late_penalty_amount' => 0,
                'total_amount' => $amount,
                'liability' => FineLiability::PendingReview,
                'status' => 'new',
            ]);

            // The first two are settled; the rest wait for a human decision, which
            // is the point of ADR-011.
            if ($i < 2 && $booking !== null) {
                app(FineLiabilityService::class)
                    ->confirmLiability($fine, FineLiability::Customer->value, $this->manager->id);

                $payments->assignFine($fine->fresh(), $this->manager->id);
            }

            $created++;
        }

        $this->note("  fines: {$created} recorded, 2 assigned to customers");
    }

    private function payroll(): void
    {
        $payments = app(PaymentService::class);

        foreach ([2, 1] as $monthsAgo) {
            $month = $this->today->subMonths($monthsAgo)->startOfMonth();

            $run = PayrollRun::query()->create([
                'branch_id' => $this->branch->id,
                'period_month' => $month,
                'status' => PayrollStatus::Draft,
            ]);

            foreach ($this->employees as $employee) {
                $base = (float) $employee->base_salary;
                $commission = fake()->boolean(40) ? fake()->numberBetween(2000, 12000) : 0;
                $social = round($base * 0.09, 2);

                PayrollItem::query()->create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'base_salary' => $base,
                    'commissions_amount' => $commission,
                    'social_contributions' => $social,
                    'gross_amount' => $base + $commission,
                    'net_amount' => $base + $commission - $social,
                    'status' => 'approved',
                ]);
            }

            // Approve accrues what is owed to staff; pay clears it against the bank.
            $payments->approvePayroll($run->fresh(), $this->accountant->id);
            $run->update(['status' => PayrollStatus::Approved, 'approved_by_id' => $this->manager->id, 'approved_at' => $month->addDays(25)]);

            if ($monthsAgo > 1) {
                $payments->payPayroll($run->fresh(), $this->accountant->id);
                $run->update(['status' => PayrollStatus::Paid, 'paid_at' => $month->addDays(28)]);
            }
        }

        $this->note('  payroll: 2 runs (1 paid, 1 approved and outstanding)');
    }

    private function tillShift(): void
    {
        $register = app(CashRegisterService::class);

        $session = $register->openSession($this->cashBox, 20000, $this->receptionist);

        // Close a little short, so the variance alert and the cash-over/short
        // posting both have something real behind them.
        $expected = (float) $register->calculateExpected($session);
        $register->closeSession($session, number_format($expected - 1500, 2, '.', ''), $this->receptionist);

        // And leave one open, which is the normal mid-shift state.
        $register->openSession($this->bank, 0, $this->accountant);

        $this->note('  till: 1 shift closed with a 1 500 DZD shortage, 1 open');
    }

    private function summarise(): void
    {
        $reports = app(ReportService::class);
        $from = $this->today->subDays(self::HISTORY_DAYS);

        $this->command->newLine();
        $this->command->info('Demo data ready.');
        $this->note('  transactions posted : '.Transaction::query()->count());
        $this->note('  bookings            : '.Booking::query()->count());
        $this->note('  customers           : '.Customer::query()->count());
        $this->note('  cars                : '.Car::query()->count());
        $this->command->newLine();
        $this->note('  Sign in as manager@mcars.dz / password');

        unset($reports, $from);
    }
}
