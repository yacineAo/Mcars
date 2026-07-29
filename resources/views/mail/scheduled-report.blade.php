<x-mail::message>
# {{ __('reports.scheduled_mail.heading', ['name' => $reportName]) }}

{{ __('reports.scheduled_mail.body') }}

{{ __('reports.scheduled_mail.generated_at', ['date' => $generatedAt->format('d/m/Y H:i')]) }}

{{ __('reports.scheduled_mail.regards') }},<br>
{{ config('app.name') }}
</x-mail::message>
