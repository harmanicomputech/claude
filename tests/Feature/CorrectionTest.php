<?php

namespace Tests\Feature;

use App\Enums\ResultStatus;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class CorrectionTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private Result $original;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd();

        $this->ussd($this->resultInput());
        $this->original = Result::sole();
    }

    private function requestCorrection(?Agent $agent = null): Result
    {
        $this->ussd('1*'.self::PU.'*1*310*130*80*20*5*1*'.self::PIN, $agent)
            ->assertSee('END Correction sent for review ✔', false);

        return Result::where('status', ResultStatus::Pending)->sole();
    }

    public function test_second_submission_offers_a_correction(): void
    {
        $this->ussd('1*'.self::PU)
            ->assertContent("CON Result already submitted\nfor this PU.\n1. Request correction\n2. Exit");
        $this->ussd('1*'.self::PU.'*2')->assertContent('END Thank you');
        $this->ussd('1*'.self::PU.'*1')->assertContent("CON Amachi Pry Sch\nCORRECTION\nAccredited Voters:");
        $this->ussd('1*'.self::PU.'*1*310*130*80*20*5')->assertSee("CON Confirm CORRECTION:\nPU:110101001", false);
    }

    public function test_correction_is_stored_as_pending(): void
    {
        $correction = $this->requestCorrection();

        $this->assertTrue($correction->corrects->is($this->original));
        $this->assertSame(['APC' => 130, 'PDP' => 80, 'LP' => 20], $correction->votesByParty());
        $this->assertNull($correction->accepted_polling_unit_code);
        $this->assertSame(ResultStatus::Accepted, $this->original->refresh()->status);

        Queue::assertPushed(SendSms::class, fn (SendSms $job) => str_contains($job->message, "Correction received and awaiting review. Ref: {$correction->reference}"));
    }

    public function test_approving_replaces_the_accepted_result(): void
    {
        $correction = $this->requestCorrection();

        $this->artisan('result:review', ['action' => 'approve', 'reference' => $correction->reference, '--by' => 'Coordinator'])
            ->assertSuccessful();

        $this->assertSame(ResultStatus::Accepted, $correction->refresh()->status);
        $this->assertSame(self::PU, $correction->accepted_polling_unit_code);
        $this->assertSame('Coordinator', $correction->reviewed_by);
        $this->assertSame(ResultStatus::Superseded, $this->original->refresh()->status);
        $this->assertNull($this->original->accepted_polling_unit_code);

        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->message === "Your correction {$correction->reference} for PU ".self::PU.' was approved.');
    }

    public function test_rejecting_keeps_the_original(): void
    {
        $correction = $this->requestCorrection();

        $this->artisan('result:review', ['action' => 'reject', 'reference' => $correction->reference])->assertSuccessful();

        $this->assertSame(ResultStatus::Rejected, $correction->refresh()->status);
        $this->assertSame(ResultStatus::Accepted, $this->original->refresh()->status);
    }

    public function test_a_correction_can_only_be_reviewed_once(): void
    {
        $correction = $this->requestCorrection();

        $this->artisan('result:review', ['action' => 'approve', 'reference' => $correction->reference])->assertSuccessful();
        $this->artisan('result:review', ['action' => 'reject', 'reference' => $correction->reference])->assertFailed();
    }

    public function test_later_correction_supersedes_an_approved_one(): void
    {
        $first = $this->requestCorrection();
        $this->artisan('result:review', ['action' => 'approve', 'reference' => $first->reference]);

        $second = $this->requestCorrection();
        $this->assertTrue($second->corrects->is($first));

        $this->artisan('result:review', ['action' => 'approve', 'reference' => $second->reference]);

        $this->assertSame(1, Result::where('status', ResultStatus::Accepted)->count());
        $this->assertSame(ResultStatus::Accepted, $second->refresh()->status);
    }

    public function test_pending_corrections_are_listed(): void
    {
        $correction = $this->requestCorrection();

        $this->artisan('result:review', ['action' => 'list'])
            ->expectsOutputToContain($correction->reference)
            ->assertSuccessful();
    }

    // API -------------------------------------------------------------------

    public function test_api_requires_a_token(): void
    {
        $this->getJson('/api/corrections')->assertStatus(503);

        config(['election.api_token' => 'api-secret']);

        $this->getJson('/api/corrections')->assertUnauthorized();
        $this->getJson('/api/corrections', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    public function test_api_lists_and_approves_corrections(): void
    {
        config(['election.api_token' => 'api-secret']);
        $headers = ['Authorization' => 'Bearer api-secret'];
        $correction = $this->requestCorrection();

        $this->getJson('/api/corrections', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.reference', $correction->reference)
            ->assertJsonPath('data.0.corrects_reference', $this->original->reference)
            ->assertJsonPath('data.0.votes.APC', 130);

        $this->postJson("/api/corrections/{$correction->reference}/approve", ['reviewed_by' => 'Dashboard user', 'note' => 'Matches EC8A photo'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.reviewed_by', 'Dashboard user');

        $this->postJson("/api/corrections/{$correction->reference}/reject", [], $headers)->assertStatus(409);
        $this->postJson('/api/corrections/RS000000/approve', [], $headers)->assertNotFound();
    }

    public function test_api_rejects_corrections(): void
    {
        config(['election.api_token' => 'api-secret']);
        $correction = $this->requestCorrection();

        $this->postJson("/api/corrections/{$correction->reference}/reject", ['note' => 'Figures do not match'], ['Authorization' => 'Bearer api-secret'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'Figures do not match');
    }
}
