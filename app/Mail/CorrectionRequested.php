<?php

namespace App\Mail;

use App\Models\Result;
use App\Support\Queues;
use App\Support\Rehearsal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CorrectionRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Result $result)
    {
        $this->onQueue(Queues::MAIL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: Rehearsal::prefix()."Correction needs review: PU {$this->result->polling_unit_code} ({$this->result->reference})",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.correction-requested');
    }
}
