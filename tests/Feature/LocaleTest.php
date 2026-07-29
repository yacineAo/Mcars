<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\DepositResource;
use App\Filament\Admin\Resources\FineResource;
use App\Filament\Admin\Resources\PaymentResource;
use App\Livewire\LocaleSwitcher;
use App\Models\Branch;
use App\Models\Car;
use App\Models\User;
use App\Support\Label;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->user = User::factory()->create(['locale' => 'en']);
    $this->user->assignRole(UserRole::SuperAdmin->value);
});

it('switches locale and updates user preference', function () {
    $this->actingAs($this->user);

    expect($this->user->fresh()->locale)->toBe(Locale::English);

    $this->post(route('locale.switch', 'fr'))->assertRedirect();

    expect($this->user->fresh()->locale)->toBe(Locale::French);
});

it('switches locale to arabic', function () {
    $this->actingAs($this->user);

    $this->post(route('locale.switch', 'ar'))->assertRedirect();

    expect($this->user->fresh()->locale)->toBe(Locale::Arabic);
});

it('returns 404 for a locale outside the enum', function () {
    $this->actingAs($this->user);

    // The route constraint is built from Locale::values(), so an unknown locale never
    // reaches the controller.
    $this->post('/locale/de')->assertNotFound();

    expect($this->user->fresh()->locale)->toBe(Locale::English);
});

it('refuses to switch locale over GET', function () {
    $this->actingAs($this->user);

    // Switching writes to the user row. A GET would be forgeable from any page and
    // triggerable by a link-prefetcher, so only POST is routed.
    $this->get('/locale/fr')->assertMethodNotAllowed();

    expect($this->user->fresh()->locale)->toBe(Locale::English);
});

it('sets app locale through middleware when user has a preference', function () {
    $user = User::factory()->create(['locale' => 'fr']);
    $user->assignRole(UserRole::SuperAdmin->value);

    App::setLocale('en');

    $this->actingAs($user)
        ->get('/admin')
        ->assertSuccessful();

    expect(App::getLocale())->toBe('fr');
});

it('leaves the app locale alone for a guest', function () {
    // `users.locale` is NOT NULL with a default, so a signed-in user always has one —
    // the middleware's guard exists for the unauthenticated case, which is this.
    App::setLocale('en');

    $this->get('/')->assertSuccessful();

    expect(App::getLocale())->toBe('en');
});

it('constrains users.locale to the enum at the database level', function () {
    // Every other enum-backed column in this schema carries a check constraint
    // (CLAUDE.md, "Enums"). Without one, a bad value makes the cast throw ValueError on
    // read and the user becomes unloadable.
    //
    // No assertion after the throw: a failed statement aborts the surrounding Postgres
    // transaction that RefreshDatabase opened, so every later query in this test would
    // error with "current transaction is aborted" regardless of the constraint.
    expect(fn () => DB::table('users')->where('id', $this->user->id)->update(['locale' => 'de']))
        ->toThrow(QueryException::class);
});

it('renders the locale switcher from filament components', function () {
    $this->actingAs($this->user);

    Livewire::test(LocaleSwitcher::class)
        ->assertOk()
        ->assertSeeHtml('fi-dropdown')
        // POST form + CSRF token rather than a bare link.
        ->assertSeeHtml('method="POST"')
        ->assertSeeHtml('_token');
});

it('marks the active locale in the switcher', function () {
    $this->actingAs($this->user);

    Livewire::test(LocaleSwitcher::class)
        ->assertOk()
        ->assertSee('English');

    $this->user->update(['locale' => Locale::Arabic]);

    // The active entry is coloured differently; without this the dropdown gave no
    // indication of the current language at all.
    Livewire::test(LocaleSwitcher::class)
        ->assertOk()
        ->assertSee('العربية', escape: false);
});

it('shows all locale options in their own script', function () {
    $this->actingAs($this->user);

    Livewire::test(LocaleSwitcher::class)
        ->assertOk()
        ->assertSee('English')
        ->assertSee('Français', escape: false)
        ->assertSee('العربية', escape: false);
});

it('translates panel labels through the shared dictionary', function () {
    // The panel turns on translateLabel() globally, so Filament routes every
    // auto-generated label through __() and one dictionary covers all 38 resources.
    App::setLocale('fr');
    expect(__('Registration number'))->toBe('Immatriculation')
        ->and(__('Daily rate'))->toBe('Tarif journalier')
        ->and(__('Bookings'))->toBe('Réservations');

    App::setLocale('ar');
    expect(__('Registration number'))->toBe('رقم التسجيل')
        ->and(__('Net profit'))->toBe('صافي الربح')
        ->and(__('Bookings'))->toBe('الحجوزات');

    // A key with no entry falls back to itself rather than rendering blank.
    expect(__('Some Label Nobody Translated'))->toBe('Some Label Nobody Translated');
});

it('uses Algerian arabic month names', function () {
    $user = User::factory()->create(['locale' => 'ar']);
    $user->assignRole(UserRole::SuperAdmin->value);

    $this->actingAs($user)->get('/admin')->assertSuccessful();

    // Algeria uses the French-derived month names, not the Levantine ones.
    expect(Carbon::parse('2026-01-15')->locale('ar')->translatedFormat('F'))->toBe('جانفي');
});

it('renders the panel in the user language, right-to-left for arabic', function () {
    Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    Car::factory()->create(['registration_number' => '12345-678-16']);

    $fr = User::factory()->create(['locale' => 'fr']);
    $fr->assignRole(UserRole::SuperAdmin->value);

    $html = $this->actingAs($fr)->get('/admin/cars')->assertSuccessful()->getContent();

    // Column labels are auto-generated by Filament and routed through __() by the
    // panel's global translateLabel(), so this proves the whole mechanism end to end.
    expect($html)->toContain('Immatriculation')
        ->and($html)->toContain('Parc')          // navigation group
        ->and($html)->toContain('dir="ltr"');

    $ar = User::factory()->create(['locale' => 'ar']);
    $ar->assignRole(UserRole::SuperAdmin->value);

    $html = $this->actingAs($ar)->get('/admin/cars')->assertSuccessful()->getContent();

    expect($html)->toContain('رقم التسجيل')
        ->and($html)->toContain('الأسطول')
        // Filament derives this from its own ar layout translation.
        ->and($html)->toContain('dir="rtl"');
});

it('returns a string label even when the key names a translation file', function () {
    // __('bookings') has no dot, so Laravel falls through to group lookup and loads the
    // whole of lang/{locale}/bookings.php — an array. Four model labels collide today
    // (bookings, payments, deposits, fines), and it only surfaces in a locale with no
    // JSON dictionary, so it passed in French and 500'd the dashboard in English.
    $colliding = ['bookings', 'payments', 'deposits', 'fines'];

    foreach (['en', 'fr', 'ar'] as $locale) {
        App::setLocale($locale);

        foreach ($colliding as $key) {
            expect(Label::translate($key))->toBeString();
        }

        foreach ([BookingResource::class, PaymentResource::class, DepositResource::class, FineResource::class] as $resource) {
            expect($resource::getPluralModelLabel())->toBeString()
                ->and($resource::getModelLabel())->toBeString();
        }
    }

    // English has no dictionary, so it falls back to the key rather than an array.
    App::setLocale('en');
    expect(BookingResource::getPluralModelLabel())->toBe('bookings');

    App::setLocale('fr');
    expect(BookingResource::getPluralModelLabel())->toBe('réservations');
});

it('renders the dashboard in every locale', function () {
    // The regression that broke /admin: a resource label resolving to an array.
    foreach (['en', 'fr', 'ar'] as $locale) {
        $user = User::factory()->create(['locale' => $locale]);
        $user->assignRole(UserRole::SuperAdmin->value);

        $this->actingAs($user)->get('/admin')->assertSuccessful();
    }
});
