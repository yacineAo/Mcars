<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-branch feature flag
    |--------------------------------------------------------------------------
    |
    | When false (default in Phase 10 deployment), the BranchScope global scope,
    | branch switcher, and all branch-based restrictions are disabled. The
    | system behaves exactly as it did in Phase 0–9.
    |
    | Set to true only after every row has a branch_id (D3 backfill complete)
    | and all user-branch pivots are configured.
    |
    | See docs/08-multi-branch-retrofit.md §Deploy D4 for the safe cutover
    | sequence.
    |
    */
    'enabled' => env('BRANCHES_ENABLED', false),
];
