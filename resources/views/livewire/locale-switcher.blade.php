<div>
    <x-filament::dropdown placement="bottom-end">
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-language"
                :label="__('common.language')"
                :tooltip="__('common.language')"
                color="gray"
            />
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($locales as $locale)
                {{-- A form, not a link: switching writes to the user row, so it needs
                     POST and a CSRF token. A plain <a> here could be triggered by a
                     link-prefetcher or forged from any other page. --}}
                <form
                    method="POST"
                    action="{{ route('locale.switch', $locale->value) }}"
                >
                    @csrf

                    <x-filament::dropdown.list.item
                        tag="button"
                        type="submit"
                        :icon="$locale->getIcon()"
                        :color="$locale === $current ? 'primary' : 'gray'"
                    >
                        {{ $locale->getLabel() }}
                    </x-filament::dropdown.list.item>
                </form>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
