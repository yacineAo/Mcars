@php($rows = \App\Support\ActivityChanges::rows($getRecord()))

@if ($rows === [])
    <div class="text-sm text-gray-500 dark:text-gray-400">—</div>
@else
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                    <th class="px-4 py-2 font-medium">{{ __('activity_log.resource.diff.field') }}</th>
                    <th class="px-4 py-2 font-medium">{{ __('activity_log.resource.diff.before') }}</th>
                    <th class="px-4 py-2 font-medium">{{ __('activity_log.resource.diff.after') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2 font-medium text-gray-950 dark:text-white">{{ $row['field'] }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $row['old'] }}</td>
                        <td class="px-4 py-2 text-gray-950 dark:text-white">{{ $row['new'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
