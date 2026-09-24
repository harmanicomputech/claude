<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendTestEmail extends Command
{
    protected $signature = 'election:test-email {to? : Address to send to (defaults to NOTIFY_EMAILS)}';

    protected $description = 'Send a test email right away to check the mail settings';

    public function handle(): int
    {
        $recipients = $this->argument('to') ? [$this->argument('to')] : config('ussd.notify_emails');

        if ($recipients === []) {
            $this->error('No recipient: pass an address or set NOTIFY_EMAILS.');

            return self::FAILURE;
        }

        try {
            // Sent immediately (not queued) so any SMTP error shows up here.
            Mail::raw(
                'This is a test email from '.config('app.name').'. If you can read this, email notifications are working.',
                fn ($message) => $message->to($recipients)->subject(config('app.name').' test email'),
            );
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Test email sent to '.implode(', ', $recipients).' via '.config('mail.default').' ('.config('mail.mailers.smtp.host').').');

        return self::SUCCESS;
    }
}
