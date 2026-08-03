<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Locale;
use App\Listeners\RecordLastLogin;
use App\Models\CarDocument;
use App\Observers\CarDocumentObserver;
use Carbon\Translator;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Algerian Arabic month names.
     *
     * The Maghreb uses the French-derived names — جانفي, فيفري, أفريل — where Carbon's
     * stock `ar` locale ships the Levantine/MSA set (يناير, فبراير, أبريل). A date on a
     * contract or an owner statement has to read the way it does locally.
     */
    private const DZ_MONTHS = [
        'جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان',
        'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(Login::class, RecordLastLogin::class);

        CarDocument::observe(CarDocumentObserver::class);

        // Targets the 'ar' translator singleton, not Carbon::getTranslator(): the latter
        // returns the translator for whatever the *current* locale is, so overriding
        // Arabic messages on it is a no-op unless Carbon already happens to be Arabic.
        //
        // Registered once per process rather than per request, so it holds on the queue
        // and in console commands too — a scheduled Arabic PDF has no session to infer
        // a locale from.
        Translator::get(Locale::Arabic->value)->setMessages(Locale::Arabic->value, [
            'months' => self::DZ_MONTHS,
            'months_short' => self::DZ_MONTHS,
        ]);
    }
}
