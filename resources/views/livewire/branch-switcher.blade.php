<div
    x-data="{ open: false }"
    class="relative"
>
    <button
        type="button"
        @click="open = !open"
        @click.away="open = false"
        class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 rounded-lg hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition-colors"
    >
        <x-filament::icon
            icon="heroicon-o-building-storefront"
            class="w-5 h-5"
        />

        <span>
            @if ($currentBranch)
                {{ $currentBranch->name }}
            @elseif ($canViewAll)
                {{ __('reports.all_branches') }}
            @else
                {{ Auth::user()->branch?->name ?? __('reports.all_branches') }}
            @endif
        </span>

        <x-filament::icon
            icon="heroicon-o-chevron-down"
            class="w-4 h-4"
        />
    </button>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute right-0 mt-1 w-56 bg-white dark:bg-gray-800 rounded-lg shadow-lg ring-1 ring-black/5 dark:ring-white/10 z-50 py-1"
    >
        @if ($canViewAll)
            <button
                type="button"
                wire:click="switch(null)"
                class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 {{ $activeBranchId === null ? 'bg-primary-50 dark:bg-primary-900/20 font-medium text-primary-600 dark:text-primary-400' : '' }}"
            >
                <span class="flex items-center gap-2">
                    <x-filament::icon
                        icon="heroicon-o-globe-alt"
                        class="w-4 h-4"
                    />
                    {{ __('reports.all_branches') }}
                </span>
            </button>

            @if ($accessibleBranches->isNotEmpty())
                <div class="border-t border-gray-200 dark:border-gray-700 my-1"></div>
            @endif
        @endif

        @foreach ($accessibleBranches as $branch)
            <button
                type="button"
                wire:click="switch({{ $branch->id }})"
                class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 {{ $activeBranchId === $branch->id ? 'bg-primary-50 dark:bg-primary-900/20 font-medium text-primary-600 dark:text-primary-400' : '' }}"
            >
                <span class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-6 h-6 text-xs font-bold rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                        {{ $branch->code }}
                    </span>
                    {{ $branch->name }}
                </span>
            </button>
        @endforeach
    </div>
</div>
