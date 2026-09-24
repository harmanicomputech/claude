<x-mail::message>
# Incident Reported: {{ $incident->type->label() }}

<x-mail::table>
| | |
| :-- | :-- |
| **Reference** | {{ $incident->reference }} |
| **Type** | {{ $incident->type->label() }} |
| **Polling Unit** | {{ $incident->polling_unit_code }} |
| **Note** | {{ $incident->note }} |
| **Agent** | {{ $incident->agent->name }} ({{ $incident->agent->phone_number }}) |
| **Reported** | {{ $incident->created_at->timezone(config('ussd.timezone'))->format('j M Y, g:i A') }} |
</x-mail::table>

{{ config('app.name') }}
</x-mail::message>
