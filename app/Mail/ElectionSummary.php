<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ElectionSummary extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary  from ElectionStats::summary()
     */
    public function __construct(public array $summary) {}

    public function envelope(): Envelope
    {
        $at = Carbon::parse($this->summary['generated_at'])->timezone(config('election.timezone'))->format('g:i A');

        return new Envelope(
            subject: "Election Shield summary {$at}: {$this->summary['results']['polling_units']}/{$this->summary['polling_units']} PUs reported",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.election-summary');
    }
}
