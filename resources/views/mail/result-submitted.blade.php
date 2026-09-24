<x-mail::message>
# Result Submitted ✔

@include('mail.partials.result-table', ['result' => $result])

{{ config('app.name') }}
</x-mail::message>
