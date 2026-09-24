<x-mail::message>
# {{ $summary['election'] }} — Summary

As of {{ \Illuminate\Support\Carbon::parse($summary['generated_at'])->timezone(config('election.timezone'))->format('j M Y, g:i A') }}

<x-mail::table>
| | |
| :-- | --: |
| **Polling units** | {{ number_format($summary['polling_units']) }} |
| **Agents checked in (PUs)** | {{ number_format($summary['presence']['polling_units']) }} ({{ $summary['presence']['percent'] }}%) |
| **Results received (PUs)** | {{ number_format($summary['results']['polling_units']) }} ({{ $summary['results']['percent'] }}%) |
| **Corrections awaiting review** | {{ number_format($summary['results']['pending_corrections']) }} |
| **Incidents (last hour / total)** | {{ number_format($summary['incidents']['last_hour']) }} / {{ number_format($summary['incidents']['total']) }} |
</x-mail::table>

## Votes so far

<x-mail::table>
| Party | Votes |
| :-- | --: |
@foreach ($summary['results']['party_votes'] as $party => $votes)
| {{ $party }} | {{ number_format($votes) }} |
@endforeach
| **Total valid** | **{{ number_format($summary['results']['total_valid_votes']) }}** |
| Rejected | {{ number_format($summary['results']['rejected_votes']) }} |
| Accredited voters | {{ number_format($summary['results']['accredited_voters']) }} |
</x-mail::table>

@if ($summary['incidents']['by_type'])
## Incidents by type

<x-mail::table>
| Type | Count |
| :-- | --: |
@foreach ($summary['incidents']['by_type'] as $type => $count)
| {{ \App\Enums\IncidentType::from($type)->label() }} | {{ number_format($count) }} |
@endforeach
</x-mail::table>
@endif

@if ($summary['by_lga'])
## By LGA

<x-mail::table>
| LGA | PUs | Checked in | Results | % |
| :-- | --: | --: | --: | --: |
@foreach ($summary['by_lga'] as $row)
| {{ $row['lga'] }} | {{ number_format($row['polling_units']) }} | {{ number_format($row['presence']) }} | {{ number_format($row['results']) }} | {{ $row['results_percent'] }}% |
@endforeach
</x-mail::table>
@endif

{{ config('app.name') }}
</x-mail::message>
