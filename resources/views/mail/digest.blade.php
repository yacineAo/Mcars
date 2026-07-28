<div @if ($isRtl) dir="rtl" style="text-align: right;" @endif>

# {{ __('notifications.digest.heading') }}

{{ __('notifications.digest.intro', ['count' => $entries->count()]) }}

@foreach ($entries as $entry)
**{{ $entry->alertRule?->type?->getLabel() ?? $entry->template_key }}**
{{ $entry->created_at?->translatedFormat('d/m/Y H:i') }}

@foreach (($entry->payload ?? []) as $key => $value)
@continue (! is_scalar($value) || is_bool($value) || $value === '')
- {{ __('notifications.fields.'.$key) }}: {{ $value }}
@endforeach

---
@endforeach

{{ __('notifications.digest.footer') }}

{{ __('notifications.mail.signature', ['app' => config('app.name')]) }}

</div>
