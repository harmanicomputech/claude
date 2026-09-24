<x-mail::message>
# Result Submitted ✔

<x-mail::table>
| | |
| :-- | :-- |
| **Reference** | {{ $result->reference }} |
| **Polling Unit** | {{ $result->polling_unit_code }} |
| **Candidate Votes** | {{ number_format($result->candidate_votes) }} |
| **Total Votes Cast** | {{ number_format($result->total_votes) }} |
| **Agent** | {{ $result->agent->name }} ({{ $result->agent->phone_number }}) |
| **Submitted** | {{ $result->created_at->timezone(config('ussd.timezone'))->format('j M Y, g:i A') }} |
</x-mail::table>

{{ config('app.name') }}
</x-mail::message>
