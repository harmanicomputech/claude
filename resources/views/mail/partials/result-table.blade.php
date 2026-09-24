<x-mail::table>
| | |
| :-- | :-- |
| **Reference** | {{ $result->reference }} |
| **Polling Unit** | {{ $result->polling_unit_code }}@if ($result->pollingUnit) — {{ $result->pollingUnit->name }}, {{ $result->pollingUnit->ward }}, {{ $result->pollingUnit->lga }}@endif |
| **Accredited Voters** | {{ number_format($result->accredited_voters) }} |
@foreach ($result->votesByParty() as $party => $votes)
| **{{ $party }}** | {{ number_format($votes) }} |
@endforeach
| **Total Valid Votes** | {{ number_format($result->total_valid_votes) }} |
| **Rejected Votes** | {{ number_format($result->rejected_votes) }} |
| **Total Votes Cast** | {{ number_format($result->total_votes_cast) }} |
| **Agent** | {{ $result->agent->name }} ({{ $result->agent->phone_number }}) |
| **Submitted** | {{ $result->created_at->timezone(config('election.timezone'))->format('j M Y, g:i A') }} |
</x-mail::table>
