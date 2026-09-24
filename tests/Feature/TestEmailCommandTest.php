<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TestEmailCommandTest extends TestCase
{
    public function test_it_sends_to_the_notify_list(): void
    {
        config(['ussd.notify_emails' => ['coordinator@example.com']]);

        $this->artisan('election:test-email')->expectsOutputToContain('Test email sent to coordinator@example.com')->assertSuccessful();

        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('coordinator@example.com', $sent->first()->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_it_needs_a_recipient(): void
    {
        $this->artisan('election:test-email')->assertFailed();
    }
}
