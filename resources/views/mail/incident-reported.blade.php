<x-mail::message>
# @if ($incident->isUrgent())URGENT — @endif Incident Reported: {{ $incident->type->label() }}

<x-mail::table>
| | |
| :-- | :-- |
| **Reference** | {{ $incident->reference }} |
| **Type** | {{ $incident->type->label() }} |
| **Polling Unit** | {{ $incident->polling_unit_code }}@if ($incident->pollingUnit) — {{ $incident->pollingUnit->name }}, {{ $incident->pollingUnit->ward }}, {{ $incident->pollingUnit->lga }}@endif |
| **Note** | {{ $incident->note }} |
| **Reported by** | {{ $incident->agent ? $incident->agent->name.' ('.$incident->agent->phone_number.')' : 'Member of the public ('.$incident->reporter_phone.')' }} |
| **Reported** | {{ $incident->created_at->timezone(config('election.timezone'))->format('j M Y, g:i A') }} |
</x-mail::table>

{{ config('app.name') }}
</x-mail::message>
