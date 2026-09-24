<?php

namespace Tests\Feature;

use App\Support\ElectionCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class SubmissionWindowTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd();

        config([
            'election.enforce_windows' => true,
            'election.date' => '2027-02-06',
            'election.presence_opens_at' => '07:00',
            'election.results_open_at' => '14:30',
            'election.results_close_at' => '2027-02-08 23:59',
        ]);
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Africa/Lagos'));
    }

    public function test_presence_opens_at_seven(): void
    {
        $this->at('2027-02-06 06:59');
        $this->ussd('3')->assertContent("END Presence check-in opens\n6 Feb, 7:00 AM.");

        $this->at('2027-02-06 07:00');
        $this->ussd('3')->assertContent('CON Enter PU Code:');
    }

    public function test_results_open_after_polls_close(): void
    {
        $this->at('2027-02-06 14:29');
        $this->ussd('1')->assertContent("END Result submission opens\n6 Feb, 2:30 PM.");

        $this->at('2027-02-06 14:30');
        $this->ussd('1')->assertContent('CON Enter PU Code:');
    }

    public function test_results_close(): void
    {
        $this->at('2027-02-09 00:00');
        $this->ussd('1')->assertContent("END Result submission closed\n8 Feb, 11:59 PM.");
    }

    public function test_incidents_can_be_reported_any_time(): void
    {
        $this->at('2027-02-05 12:00');
        $this->ussd('2')->assertSee('CON Incident Type:', false);
    }

    public function test_calendar_helpers(): void
    {
        $calendar = app(ElectionCalendar::class);

        $this->at('2027-02-06 06:00');
        $this->assertTrue($calendar->isElectionDay());
        $this->assertFalse($calendar->inReportingPeriod());

        $this->at('2027-02-06 09:00');
        $this->assertTrue($calendar->inReportingPeriod());

        $this->at('2027-02-09 09:00');
        $this->assertFalse($calendar->isElectionDay());
        $this->assertFalse($calendar->inReportingPeriod());
    }
}
