@extends('admin.layout')

@section('title', 'Agent cards')

@php
    $code = config('ussd.service_code');
    $parties = implode(' → ', config('election.parties'));
    $rehearsal = \App\Support\Rehearsal::active();
    $presenceAt = \Illuminate\Support\Carbon::createFromFormat('H:i', config('election.presence_opens_at'))->format('g:i A');
    $resultsAt = \Illuminate\Support\Carbon::createFromFormat('H:i', config('election.results_open_at'))->format('g:i A');
@endphp

@section('content')
    <style>
        .cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:16px; }
        .agent-card { background:#fff; border:2px solid var(--brand); border-radius:12px; padding:16px 18px; break-inside:avoid; page-break-inside:avoid; }
        .agent-card .card-head { display:flex; justify-content:space-between; align-items:baseline; border-bottom:1px solid var(--line); padding-bottom:8px; margin-bottom:10px; }
        .agent-card .card-head b { color:var(--brand); font-size:16px; }
        .agent-card .who { font-size:18px; font-weight:700; }
        .agent-card .pu { margin:8px 0; padding:8px 10px; background:#f0f9f4; border-radius:8px; }
        .agent-card .pu .code { font-size:22px; font-weight:700; letter-spacing:1px; font-variant-numeric:tabular-nums; }
        .agent-card .keys { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:8px 0; }
        .agent-card .keys div { border:1px dashed #98a2b3; border-radius:8px; padding:6px 10px; }
        .agent-card .keys dt { font-size:12px; color:var(--muted); }
        .agent-card .keys dd { margin:0; font-size:20px; font-weight:700; }
        .agent-card ol { margin:6px 0 0; padding-left:18px; font-size:13px; line-height:1.45; }
        .agent-card .foot { margin-top:8px; font-size:12px; color:var(--muted); }
        @media print {
            @page { size:A4; margin:10mm; }
            .cards { grid-template-columns:1fr 1fr; gap:8mm; }
            .agent-card { font-size:12px; border-width:1.5px; }
        }
    </style>

    <div class="page-head no-print">
        <div>
            <h1>Agent cards</h1>
            <p class="muted">One card per agent with their PU code, the dial code and simple steps. Print, cut and hand out at training.</p>
        </div>
        <div class="actions"><button type="button" onclick="window.print()">Print cards</button></div>
    </div>

    @if ($pins)
        <div class="flash bad no-print"><b>New PINs are shown only on this page.</b> Print now. The agents' old PINs no longer work.</div>
    @endif

    <div class="card no-print">
        <form method="get" class="filters">
            <div class="wide"><label>Search</label><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, phone or PU code"></div>
            <div>
                <label>LGA</label>
                <select name="lga">
                    <option value="">All LGAs</option>
                    @foreach ($lgas as $lga)<option @selected(($filters['lga'] ?? null) === $lga)>{{ $lga }}</option>@endforeach
                </select>
            </div>
            <div class="actions" style="flex:0 0 auto"><button type="submit" class="secondary">Show</button></div>
        </form>

        @if (auth()->user()->isAdmin() && ! $pins && $agents->total())
            <form method="post" action="{{ route('admin.agents.cards.pins', array_filter([...$filters, 'page' => $agents->currentPage()])) }}" style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
                @csrf
                <p class="muted" style="margin-top:0">PINs are stored scrambled, so existing PINs can't be printed. To print PINs on the cards, set new ones for the {{ $agents->count() }} agent(s) on this page:</p>
                <label class="inline"><input type="checkbox" name="confirm" value="1"> I understand these agents' current PINs will stop working</label>
                <p><button type="submit" class="danger">Set new PINs and show cards</button></p>
            </form>
        @endif
    </div>

    <p class="muted no-print">{{ number_format($agents->total()) }} agent(s) · page {{ $agents->currentPage() }} of {{ $agents->lastPage() }} ({{ $agents->perPage() }} cards per page)</p>

    <div class="cards">
        @forelse ($agents as $agent)
            @php($unit = $agent->pollingUnit)
            @php($coordinator = $coordinatorFor($unit?->lga))
            <article class="agent-card">
                <div class="card-head"><b>Election Shield{{ $rehearsal ? ' · REHEARSAL' : '' }}</b><span class="muted">Polling agent card</span></div>
                <div class="who">{{ $agent->name }}</div>
                <div class="muted">{{ $agent->phone_number }} (dial from this phone)</div>

                <div class="pu">
                    @if ($unit)
                        <div class="muted" style="font-size:12px">Your polling unit</div>
                        <div class="code">{{ $unit->code }}</div>
                        <div>{{ $unit->name }} · {{ $unit->ward }}, {{ $unit->lga }}</div>
                    @else
                        <div class="muted" style="font-size:12px">Polling unit</div>
                        <div>Enter your PU code when asked: ______________</div>
                    @endif
                </div>

                <dl class="keys">
                    <div><dt>Dial</dt><dd>{{ $code }}</dd></div>
                    <div><dt>Your PIN</dt><dd>{{ $pins[$agent->id] ?? '• • • •' }}</dd></div>
                </dl>
                @unless (isset($pins[$agent->id]))<div class="foot" style="margin-top:0">Your PIN was sent to you by SMS. Never share it.</div>@endunless

                <ol>
                    <li><b>At the PU (from {{ $presenceAt }}):</b> dial, press <b>3</b>{{ $unit ? '' : ', enter PU code' }}.</li>
                    <li><b>Materials:</b> dial, press <b>4</b>, then 1 arrived / 2 incomplete / 3 not arrived. Report again when it changes.</li>
                    <li><b>After counting (from {{ $resultsAt }}):</b> dial, press <b>1</b>{{ $unit ? '' : ', PU code' }}, then enter accredited voters → {{ $parties }} → rejected votes. Check the screen, press <b>1</b>, enter your PIN.</li>
                    <li><b>Any problem:</b> dial, press <b>2</b>, choose the type, type a short note, press <b>1</b>.</li>
                    <li>Keep the reference (RS… / IN…) you receive.</li>
                </ol>
                @if ($coordinator)
                    <div class="foot">Coordinator: {{ $coordinator->name }}, {{ $coordinator->phone_number }}</div>
                @endif
            </article>
        @empty
            <p class="empty">No agents match.</p>
        @endforelse
    </div>

    <div class="pagination no-print">
        @if ($agents->previousPageUrl())<a href="{{ $agents->previousPageUrl() }}">← Previous page</a>@endif
        @if ($agents->nextPageUrl())<a href="{{ $agents->nextPageUrl() }}">Next page →</a>@endif
    </div>
@endsection
