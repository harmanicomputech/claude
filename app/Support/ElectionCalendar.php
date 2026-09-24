<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Election-day times, all in the election timezone.
 */
class ElectionCalendar
{
    public function timezone(): string
    {
        return config('election.timezone');
    }

    public function date(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('election.date'), $this->timezone())->startOfDay();
    }

    /**
     * Midnight at the start of election day, in the app's (database)
     * timezone so it can be bound straight into queries.
     */
    public function dayStartsAt(): CarbonImmutable
    {
        return $this->date()->setTimezone(config('app.timezone'));
    }

    /**
     * Presence check-ins that count as "checked in": from the start of
     * election day once it has begun; before that (testing, rehearsals)
     * every check-in counts. Null means no lower bound.
     */
    public function presenceCountsFrom(): ?CarbonImmutable
    {
        return now()->greaterThanOrEqualTo($this->date()) ? $this->dayStartsAt() : null;
    }

    public function presenceOpensAt(): CarbonImmutable
    {
        return $this->onElectionDay(config('election.presence_opens_at'));
    }

    public function resultsOpenAt(): CarbonImmutable
    {
        return $this->onElectionDay(config('election.results_open_at'));
    }

    public function resultsCloseAt(): ?CarbonImmutable
    {
        $close = config('election.results_close_at');

        return filled($close) ? CarbonImmutable::parse($close, $this->timezone()) : null;
    }

    /**
     * The END message to show when presence check-in is not open, or null.
     */
    public function presenceClosedMessage(): ?string
    {
        if (! $this->enforced() || now()->greaterThanOrEqualTo($this->presenceOpensAt())) {
            return null;
        }

        return "Presence check-in opens\n".$this->format($this->presenceOpensAt()).'.';
    }

    /**
     * The END message to show when result submission is not open, or null.
     */
    public function resultsClosedMessage(): ?string
    {
        if (! $this->enforced()) {
            return null;
        }

        if (now()->lessThan($this->resultsOpenAt())) {
            return "Result submission opens\n".$this->format($this->resultsOpenAt()).'.';
        }

        $close = $this->resultsCloseAt();

        if ($close !== null && now()->greaterThan($close)) {
            return "Result submission closed\n".$this->format($close).'.';
        }

        return null;
    }

    public function isElectionDay(): bool
    {
        return now($this->timezone())->isSameDay($this->date());
    }

    /**
     * Whether coordinators should be getting hourly summaries: from presence
     * opening until results close (or the end of the day after the election).
     */
    public function inReportingPeriod(): bool
    {
        $end = $this->resultsCloseAt() ?? $this->date()->addDay()->endOfDay();

        return now()->between($this->presenceOpensAt(), $end);
    }

    public function format(CarbonImmutable $time): string
    {
        return $time->timezone($this->timezone())->format('j M, g:i A');
    }

    private function enforced(): bool
    {
        return (bool) config('election.enforce_windows');
    }

    private function onElectionDay(string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $this->date()->setTime($hour, $minute);
    }
}
