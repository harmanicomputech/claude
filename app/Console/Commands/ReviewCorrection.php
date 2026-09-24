<?php

namespace App\Console\Commands;

use App\Enums\ResultStatus;
use App\Models\Result;
use App\Services\CorrectionReviewer;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ReviewCorrection extends Command
{
    protected $signature = 'result:review
        {action : list, approve or reject}
        {reference? : Correction reference, e.g. RS784321}
        {--by= : Reviewer name}
        {--note= : Reason for the decision}';

    protected $description = 'List pending result corrections, or approve / reject one';

    public function handle(CorrectionReviewer $reviewer): int
    {
        if ($this->argument('action') === 'list') {
            return $this->listPending();
        }

        if (! in_array($this->argument('action'), ['approve', 'reject'], true) || $this->argument('reference') === null) {
            $this->error('Usage: result:review list | result:review approve|reject <reference>');

            return self::FAILURE;
        }

        $result = Result::where('reference', $this->argument('reference'))->first();

        if ($result === null) {
            $this->error('No result with that reference.');

            return self::FAILURE;
        }

        try {
            $this->argument('action') === 'approve'
                ? $reviewer->approve($result, $this->option('by'), $this->option('note'))
                : $reviewer->reject($result, $this->option('by'), $this->option('note'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Correction {$result->reference} {$this->argument('action')}d.");

        return self::SUCCESS;
    }

    private function listPending(): int
    {
        $pending = Result::with('agent', 'votes', 'corrects.votes')
            ->where('status', ResultStatus::Pending)
            ->oldest()
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No corrections waiting for review.');

            return self::SUCCESS;
        }

        $this->table(
            ['Reference', 'PU', 'Agent', 'Current', 'Proposed', 'Requested'],
            $pending->map(fn (Result $result) => [
                $result->reference,
                $result->polling_unit_code,
                "{$result->agent->name} {$result->agent->phone_number}",
                $result->corrects ? $this->describe($result->corrects) : '—',
                $this->describe($result),
                $result->created_at->timezone(config('election.timezone'))->format('j M g:i A'),
            ]),
        );

        return self::SUCCESS;
    }

    private function describe(Result $result): string
    {
        return collect($result->votesByParty())->map(fn ($votes, $party) => "{$party}:{$votes}")->implode(' ')
            ." Rej:{$result->rejected_votes} Acc:{$result->accredited_voters}";
    }
}
