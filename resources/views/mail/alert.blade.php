{{-- Arabic is RTL; the wrapper flips direction and alignment for the whole body. --}}
<div @if ($isRtl) dir="rtl" style="text-align: right;" @endif>

# {{ $alert->subject }}

{{ $alert->body }}

@if (count($alert->payload) > 0)
<table style="width: 100%; margin: 16px 0; border-collapse: collapse;">
@foreach ($alert->payload as $key => $value)
@continue (! is_scalar($value) || is_bool($value) || $value === '')
<tr>
<td style="padding: 6px 12px; border-bottom: 1px solid #e5e7eb; color: #6b7280;">
{{ __('notifications.fields.'.$key) }}
</td>
<td style="padding: 6px 12px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
{{ $value }}
</td>
</tr>
@endforeach
</table>
@endif

@if ($alert->url)
<x-mail::button :url="$alert->url">
{{ __('notifications.actions.view') }}
</x-mail::button>
@endif

{{ __('notifications.mail.signature', ['app' => config('app.name')]) }}

</div>
