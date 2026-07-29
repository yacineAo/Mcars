<div>
    {{ $this->form }}

    <div class="flex gap-2 justify-end mt-4 mb-6">
        <x-filament::button
            wire:click="export('{{ $this->getActiveReportType() }}', 'pdf')"
            icon="heroicon-o-document-text"
            color="danger"
            size="sm"
        >
            Export PDF
        </x-filament::button>

        <x-filament::button
            wire:click="export('{{ $this->getActiveReportType() }}', 'xlsx')"
            icon="heroicon-o-table-cells"
            color="success"
            size="sm"
        >
            Export Excel
        </x-filament::button>

        <x-filament::button
            wire:click="export('{{ $this->getActiveReportType() }}', 'csv')"
            icon="heroicon-o-document-arrow-down"
            color="gray"
            size="sm"
        >
            Export CSV
        </x-filament::button>
    </div>

    <x-filament::section heading="Recent Exports">
        {{ $this->table }}
    </x-filament::section>
</div>
