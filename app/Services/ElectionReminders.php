<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Result;
use App\Support\ElectionCalendar;
use App\Support\Queues;
use Illuminate\Database\Eloquent\Builder;

/**
 * Election-day SMS nudges for agents who have not checked in / reported.
 */
class ElectionReminders
{
    public function __construct(private ElectionCalendar $calendar) {}

    /**
     * Remind agents with no presence check-in today. Returns how many.
     */
    public function presence(): int
    {
        $agents = Agent::whereDoesntHave(
            'presences',
            fn (Builder $query) => $query->where('confirmed_at', '>=', $this->calendar->dayStartsAt())
        )->get();

        $code = config('ussd.service_code');

        foreach ($agents as $agent) {
            SendSms::dispatch($agent->phone_number, "Election Shield: please confirm you are at your PU. Dial {$code}, option 3.")->onQueue(Queues::BULK);
        }

        return $agents->count();
    }

    /**
     * Remind agents whose PU still has no accepted result (or, for agents
     * without an assigned PU, who have not submitted any). Returns how many.
     */
    public function results(): int
    {
        $accepted = Result::where('status', ResultStatus::Accepted)->select('polling_unit_code');

        $agents = Agent::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereNotNull('polling_unit_code')
                    ->whereNotIn('polling_unit_code', $accepted))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('polling_unit_code')
                    ->whereDoesntHave('results')))
            ->get();

        $code = config('ussd.service_code');

        foreach ($agents as $agent) {
            SendSms::dispatch($agent->phone_number, "Election Shield: your PU result has not been received. Dial {$code}, option 1 to submit.")->onQueue(Queues::BULK);
        }

        return $agents->count();
    }
}
