<?php

namespace App\Jobs;

use App\Services\AfricasTalkingSms;
use App\Support\Queues;
use App\Support\Rehearsal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued so a slow SMS gateway never delays the USSD response, which
 * Africa's Talking times out after a few seconds.
 */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public string $to, public string $message)
    {
        $this->message = Rehearsal::prefix().$message;
        $this->onQueue(Queues::HIGH);
    }

    public function handle(AfricasTalkingSms $sms): void
    {
        $sms->send($this->to, $this->message);
    }
}
