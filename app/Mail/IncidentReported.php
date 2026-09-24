<?php

namespace App\Mail;

use App\Models\Incident;
use App\Support\Queues;
use App\Support\Rehearsal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IncidentReported extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Incident $incident)
    {
        $this->onQueue(Queues::MAIL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: Rehearsal::prefix()."Incident: {$this->incident->type->label()} at PU {$this->incident->polling_unit_code} ({$this->incident->reference})",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.incident-reported');
    }
}
