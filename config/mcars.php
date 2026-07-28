<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Money
    |---------------------------------------------------------------------------
    | Ledger columns are decimal(18,2). App\Support\Money computes in integer
    | minor units; floats never touch a monetary value.
    */

    'currency' => env('MCARS_CURRENCY', 'DZD'),

    /*
    |---------------------------------------------------------------------------
    | Locales
    |---------------------------------------------------------------------------
    | Contracts are generated per-locale. 'ar' is RTL and needs the Arabic fonts
    | installed in the app image, or PDFs render as boxes.
    */

    'locales' => [
        'ar' => ['name' => 'العربية', 'dir' => 'rtl'],
        'fr' => ['name' => 'Français', 'dir' => 'ltr'],
        'en' => ['name' => 'English', 'dir' => 'ltr'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Multi-branch (ADV-06)
    |---------------------------------------------------------------------------
    | branch_id columns exist from Phase 0 (ADR-004). This flag switches on the
    | *behaviour* in Phase 10: global scope, session branch context, switcher.
    |
    | Keeping it a flag means Phase 10 can deploy and be reverted instantly
    | without a migration rollback.
    */

    'branches' => [
        'enabled' => env('BRANCHES_ENABLED', false),
        'session_key' => 'branch_context.active_id',
    ],

    /*
    |---------------------------------------------------------------------------
    | Private storage
    |---------------------------------------------------------------------------
    | Customer ID scans, licences, contract PDFs. Served only through a
    | policy-checked controller issuing short-lived signed URLs — never
    | Storage::url() on a public disk (ADR-009).
    */

    'private_disk' => env('MCARS_PRIVATE_DISK', 'local'),
    'signed_url_ttl' => 300, // seconds

];
