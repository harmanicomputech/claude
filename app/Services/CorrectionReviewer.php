<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Jobs\SendSms;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Coordinator decisions on correction requests.
 */
class CorrectionReviewer
{
    public function __construct(private DashboardOutbox $outbox) {}

    /**
     * Make the correction the PU's accepted result, superseding the old one.
     */
    public function approve(Result $correction, ?string $reviewedBy = null, ?string $note = null): Result
    {
        $superseded = DB::transaction(function () use ($correction, $reviewedBy, $note) {
            $correction = Result::whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($correction);

            $current = Result::where('accepted_polling_unit_code', $correction->polling_unit_code)
                ->lockForUpdate()
                ->first();

            // Release the PU's "accepted" slot before taking it.
            $current?->update(['status' => ResultStatus::Superseded, 'accepted_polling_unit_code' => null]);

            $correction->update([
                'status' => ResultStatus::Accepted,
                'accepted_polling_unit_code' => $correction->polling_unit_code,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewedBy,
                'review_note' => $note,
            ]);

            return $current;
        });

        $correction->refresh();

        $this->outbox->record('result.corrected', $correction->reference, [
            ...$correction->toDashboardArray(),
            'superseded_reference' => $superseded?->reference,
        ]);

        $this->notifyAgent($correction, 'approved');

        return $correction;
    }

    public function reject(Result $correction, ?string $reviewedBy = null, ?string $note = null): Result
    {
        DB::transaction(function () use ($correction, $reviewedBy, $note) {
            $locked = Result::whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($locked);

            $locked->update([
                'status' => ResultStatus::Rejected,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewedBy,
                'review_note' => $note,
            ]);
        });

        $correction->refresh();

        $this->outbox->record('result.correction_rejected', $correction->reference, $correction->toDashboardArray());

        $this->notifyAgent($correction, 'rejected');

        return $correction;
    }

    private function ensurePending(Result $result): void
    {
        if ($result->status !== ResultStatus::Pending) {
            throw new InvalidArgumentException("Result {$result->reference} is {$result->status->value}, not a pending correction.");
        }
    }

    private function notifyAgent(Result $correction, string $decision): void
    {
        if (config('ussd.sms_confirmation')) {
            SendSms::dispatch(
                $correction->agent->phone_number,
                "Your correction {$correction->reference} for PU {$correction->polling_unit_code} was {$decision}."
            );
        }
    }
}
