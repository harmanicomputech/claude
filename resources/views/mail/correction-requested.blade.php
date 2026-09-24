<x-mail::message>
# Correction Needs Review

An agent has asked to replace the accepted result for PU {{ $result->polling_unit_code }}. It will not count until a coordinator approves it.

## Proposed correction

@include('mail.partials.result-table', ['result' => $result])

@if ($result->corrects)
## Currently accepted result

@include('mail.partials.result-table', ['result' => $result->corrects])
@endif

Approve or reject it with `php artisan result:review approve {{ $result->reference }}` (or `reject`), or through the corrections API.

{{ config('app.name') }}
</x-mail::message>
