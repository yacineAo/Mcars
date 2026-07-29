{{--
    Built from Filament's own dropdown components rather than hand-written Alpine and
    Tailwind: the panel loads Filament's compiled stylesheet and registers no custom
    theme, so bespoke utility classes have no rules behind them at runtime.
--}}
<div>
    <x-filament::dropdown placement="bottom-end" width="xs">
        <x-slot name="trigger">
            <x-filament::button
                color="gray"
                size="sm"
                icon="heroicon-m-building-storefront"
            >
                @if ($currentBranch)
                    {{ $currentBranch->name }}
                @elseif ($canViewAll)
                    {{ __('reports.all_branches') }}
                @else
                    {{ auth()->user()?->branch?->name ?? __('reports.all_branches') }}
                @endif
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.list>
            @if ($canViewAll)
                <x-filament::dropdown.list.item
                    wire:click="switch(null)"
                    icon="heroicon-m-globe-alt"
                    :color="$activeBranchId === null ? 'primary' : 'gray'"
                >
                    {{ __('reports.all_branches') }}
                </x-filament::dropdown.list.item>
            @endif

            @foreach ($accessibleBranches as $branch)
                <x-filament::dropdown.list.item
                    wire:click="switch({{ $branch->id }})"
                    :badge="$branch->code"
                    :color="$activeBranchId === $branch->id ? 'primary' : 'gray'"
                >
                    {{ $branch->name }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
