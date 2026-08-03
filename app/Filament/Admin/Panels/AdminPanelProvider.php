<?php

declare(strict_types=1);

namespace App\Filament\Admin\Panels;

use App\Enums\Locale;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\EditProfile;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\RoleResource;
use App\Filament\Admin\Resources\UserResource;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\ResolveBranchContext;
use App\Http\Middleware\SetLocaleFromUser;
use App\Livewire\BranchSwitcher;
use App\Livewire\LocaleSwitcher;
use App\Support\Label;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Forms\Components\Field;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Infolists\Components\Entry;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

class AdminPanelProvider extends PanelProvider
{
    /**
     * The navigation groups, in sidebar order, with translated labels.
     *
     * Resources declare their group as a plain string; naming the groups here is what
     * lets those strings render in the user's language without editing 38 resources.
     */
    private const NAVIGATION_GROUPS = [
        'Bookings', 'Fleet', 'CRM', 'Payments', 'Accounting', 'HR', 'Operations', 'Reports', 'Settings',
    ];

    /**
     * Panel-wide presentation defaults.
     *
     * `translateLabel()` is the whole i18n strategy for this panel. Filament generates a
     * field/column/entry/filter label from the attribute name and, when this flag is
     * on, passes it through `__()`. Turning it on globally means one shared dictionary
     * (lang/{ar,fr}.json) translates every resource, instead of 38 resources each
     * carrying their own translation keys. A missing entry falls back to the English
     * key, so this can never blank a label. Ternary option labels come from Filament
     * core keys and are already translated.
     */
    public function boot(): void
    {
        Field::configureUsing(fn (Field $field): Field => $field->translateLabel());
        Column::configureUsing(fn (Column $column): Column => $column->translateLabel());
        Entry::configureUsing(fn (Entry $entry): Entry => $entry->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter): BaseFilter => $filter->translateLabel());

        // Section headings are literal strings rather than derived from an attribute, so
        // they need translating explicitly. make() sets the heading before configure()
        // runs, so it is readable here. Closures are left alone — they are evaluated
        // later and may not be strings at all.
        Section::configureUsing(function (Section $section): void {
            $heading = $section->getHeading();

            if (is_string($heading)) {
                $section->heading(Label::translate($heading));
            }
        });

        // Arabic UI with Latin digits, which is what Algerian users expect for money.
        Table::configureUsing(fn (Table $table): Table => $table->defaultNumberLocale($this->numberLocale()));
        Schema::configureUsing(fn (Schema $schema): Schema => $schema->defaultNumberLocale($this->numberLocale()));
    }

    private function numberLocale(): ?string
    {
        return app()->getLocale() === Locale::Arabic->value ? 'ar_SA@numbers=latn' : null;
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            // Round 33: the staff account screen. Locale and password live here so
            // a receptionist can maintain their own account without users.manage.
            ->profile(EditProfile::class)
            // Round 33: wires the dormant two_factor_* columns to Filament's native
            // authenticator-app MFA. Optional — staff opt in from their profile page.
            ->multiFactorAuthentication([
                AppAuthentication::make(),
            ])
            ->brandName('Mcars')
            ->colors(['primary' => Color::Blue])
            // Phase 8: the in-app bell. Filtered by notifiable, so a user only
            // ever sees notifications addressed to them.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            // Keyed by the untranslated string the resources declare — NavigationManager
            // matches the array key first, so the label is free to be translated without
            // breaking which group a resource lands in.
            ->navigationGroups(array_combine(
                self::NAVIGATION_GROUPS,
                array_map(
                    fn (string $group): NavigationGroup => NavigationGroup::make()->label(fn (): string => Label::translate($group)),
                    self::NAVIGATION_GROUPS,
                ),
            ))
            ->pages([
                Dashboard::class,
            ])
            ->resources([
                UserResource::class,
                RoleResource::class,
                BranchResource::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocaleFromUser::class,
                ResolveBranchContext::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // After Authenticate, so only signed-in staff reach it. Runs on the
                // logout route too — that is why the middleware exempts it — and
                // never on Livewire update requests, so profile saves are not bounced.
                ForcePasswordChange::class,
            ])
            // Topbar switchers. Locale is always visible; branch only when multi-branch is on.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                function (): string {
                    $html = Livewire::mount(LocaleSwitcher::class);

                    if (config('branches.enabled', false)) {
                        $html .= Livewire::mount(BranchSwitcher::class);
                    }

                    return $html;
                },
            )
            ->plugins([
                FilamentShieldPlugin::make(),
            ]);
    }
}
