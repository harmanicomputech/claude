<?php

namespace App\Ussd;

use App\Enums\IncidentType;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Services\ElectionRecorder;
use App\Support\Audit;
use App\Support\ElectionCalendar;
use App\Support\Rehearsal;

/**
 * Election Shield USSD menu.
 *
 * Africa's Talking sends the whole session so far on every request as a
 * "*"-joined string (e.g. "1*110503004*300*120*80*..."). Rather than reading
 * inputs by position, we replay them through a small state machine. That way
 * invalid inputs, "Edit" and re-entries sit naturally in the history, and
 * quick codes like *XXX*1*110503004*...# land on the right screen.
 *
 * Only terminal (END) steps write to the database, and an END closes the
 * session, so a replay never repeats a write. The one exception is counting
 * wrong PINs, which only happens for the latest input.
 */
class UssdMenu
{
    private const MAIN = 'main';

    private const RESULT_PU = 'result.pu';

    private const RESULT_EXISTS = 'result.exists';

    private const RESULT_ACCREDITED = 'result.accredited';

    private const RESULT_PARTY = 'result.party';

    private const RESULT_REJECTED = 'result.rejected';

    private const RESULT_OVER = 'result.over';

    private const RESULT_ACCREDITED_FIX = 'result.accredited_fix';

    private const RESULT_CONFIRM = 'result.confirm';

    private const RESULT_PIN = 'result.pin';

    private const INCIDENT_TYPE = 'incident.type';

    private const INCIDENT_PU = 'incident.pu';

    private const INCIDENT_NOTE = 'incident.note';

    private const INCIDENT_CONFIRM = 'incident.confirm';

    private const PRESENCE_PU = 'presence.pu';

    private const ERROR_NUMBER = 'number';

    private const ERROR_OPTION = 'option';

    private const ERROR_PU = 'pu';

    private const ERROR_PU_UNKNOWN = 'pu_unknown';

    private const ERROR_ABOVE_REGISTERED = 'above_registered';

    private const ERROR_NOTE = 'note';

    private const ERROR_PIN = 'pin';

    private Agent $agent;

    private string $state;

    /** @var array<string, mixed> */
    private array $data;

    private ?string $error;

    private bool $isLatestInput;

    public function __construct(
        private ElectionRecorder $recorder,
        private ElectionCalendar $calendar,
    ) {}

    public function handle(Agent $agent, string $text): string
    {
        if ($agent->isLocked()) {
            return "END Account locked.\nTry again later or\ncontact coordinator.";
        }

        $this->agent = $agent;
        $this->state = self::MAIN;
        $this->data = [];
        $this->error = null;

        $inputs = $this->inputs($text);
        $last = array_key_last($inputs);

        foreach ($inputs as $index => $input) {
            $this->error = null;
            $this->isLatestInput = $index === $last;

            if (($end = $this->advance($input)) !== null) {
                return 'END '.$end;
            }
        }

        return 'CON '.$this->screen();
    }

    /**
     * @return list<string>
     */
    private function inputs(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return array_map('trim', explode('*', $text));
    }

    /**
     * Apply one input to the current state. Returns the END message when the
     * session should close, or null to keep going.
     */
    private function advance(string $input): ?string
    {
        return match ($this->state) {
            self::MAIN => $this->onMainMenu($input),
            self::RESULT_PU => $this->onResultPollingUnit($input),
            self::RESULT_EXISTS => $this->onResultExists($input),
            self::RESULT_ACCREDITED => $this->onAccredited($input),
            self::RESULT_PARTY => $this->onPartyVotes($input),
            self::RESULT_REJECTED => $this->onRejected($input),
            self::RESULT_OVER => $this->onVotesOverAccredited($input),
            self::RESULT_ACCREDITED_FIX => $this->onAccredited($input),
            self::RESULT_CONFIRM => $this->onResultConfirm($input),
            self::RESULT_PIN => $this->onPin($input),
            self::INCIDENT_TYPE => $this->onIncidentType($input),
            self::INCIDENT_PU => $this->onIncidentPollingUnit($input),
            self::INCIDENT_NOTE => $this->onIncidentNote($input),
            self::INCIDENT_CONFIRM => $this->onIncidentConfirm($input),
            self::PRESENCE_PU => $this->onPresencePollingUnit($input),
        };
    }

    private function onMainMenu(string $input): ?string
    {
        return match ($input) {
            '1' => $this->calendar->resultsClosedMessage() ?? $this->startResult(correction: false),
            '2' => $this->goTo(self::INCIDENT_TYPE),
            '3' => $this->calendar->presenceClosedMessage() ?? $this->startPresence(),
            '4' => (string) config('ussd.instructions'),
            '5' => 'Thank you',
            default => $this->fail(self::ERROR_OPTION),
        };
    }

    // Flow 1: Submit Result (EC8A) -----------------------------------------

    private function startResult(bool $correction): ?string
    {
        $this->data = ['correction' => $correction];

        if (! $this->agent->hasAssignedPollingUnit()) {
            return $this->goTo(self::RESULT_PU);
        }

        $unit = PollingUnit::findByCode($this->agent->polling_unit_code);

        if ($unit === null && config('election.require_known_polling_unit')) {
            return "Your PU is not registered.\nContact coordinator.";
        }

        return $this->acceptResultPollingUnit($this->agent->polling_unit_code, $unit);
    }

    private function onResultPollingUnit(string $input): ?string
    {
        return $this->withPollingUnit($input, fn (string $code, ?PollingUnit $unit) => $this->acceptResultPollingUnit($code, $unit));
    }

    private function acceptResultPollingUnit(string $code, ?PollingUnit $unit): ?string
    {
        $this->data['pu'] = $code;
        $this->data['unit'] = $unit;

        if (! $this->recorder->hasAcceptedResultFor($code)) {
            // Nothing to correct: this is the PU's first result.
            $this->data['correction'] = false;

            return $this->goTo(self::RESULT_ACCREDITED);
        }

        return $this->data['correction']
            ? $this->goTo(self::RESULT_ACCREDITED)
            : $this->goTo(self::RESULT_EXISTS);
    }

    private function onResultExists(string $input): ?string
    {
        switch ($input) {
            case '1':
                $this->data['correction'] = true;

                return $this->goTo(self::RESULT_ACCREDITED);
            case '2':
                return 'Thank you';
            default:
                return $this->fail(self::ERROR_OPTION);
        }
    }

    private function onAccredited(string $input): ?string
    {
        if (! $this->isCount($input)) {
            return $this->fail(self::ERROR_NUMBER);
        }

        $registered = $this->data['unit']?->registered_voters;

        if ($registered !== null && (int) $input > $registered) {
            return $this->fail(self::ERROR_ABOVE_REGISTERED);
        }

        $this->data['accredited'] = (int) $input;

        if ($this->state === self::RESULT_ACCREDITED_FIX) {
            return $this->checkVotesAgainstAccredited();
        }

        $this->data['votes'] = [];

        return $this->goTo(self::RESULT_PARTY);
    }

    private function onPartyVotes(string $input): ?string
    {
        if (! $this->isCount($input)) {
            return $this->fail(self::ERROR_NUMBER);
        }

        $this->data['votes'][$this->currentParty()] = (int) $input;

        if (count($this->data['votes']) < count($this->parties())) {
            return null;
        }

        return $this->goTo(self::RESULT_REJECTED);
    }

    private function onRejected(string $input): ?string
    {
        if (! $this->isCount($input)) {
            return $this->fail(self::ERROR_NUMBER);
        }

        $this->data['rejected'] = (int) $input;

        return $this->checkVotesAgainstAccredited();
    }

    private function checkVotesAgainstAccredited(): ?string
    {
        return $this->votesCast() > $this->data['accredited']
            ? $this->goTo(self::RESULT_OVER)
            : $this->goTo(self::RESULT_CONFIRM);
    }

    private function onVotesOverAccredited(string $input): ?string
    {
        return match ($input) {
            '1' => $this->goTo(self::RESULT_ACCREDITED_FIX),
            '2' => $this->startResult($this->data['correction']),
            default => $this->fail(self::ERROR_OPTION),
        };
    }

    private function onResultConfirm(string $input): ?string
    {
        switch ($input) {
            case '1':
                if (! $this->agent->hasPin()) {
                    return "No PIN set.\nContact coordinator.";
                }

                return $this->goTo(self::RESULT_PIN);
            case '2':
                return $this->startResult($this->data['correction']);
            case '3':
                return 'Submission cancelled.';
            default:
                return $this->fail(self::ERROR_OPTION);
        }
    }

    private function onPin(string $input): ?string
    {
        if (! $this->agent->pinMatches($input)) {
            // Earlier wrong PINs in the history were already counted.
            if ($this->isLatestInput && ($this->data['pin_tries_left'] = $this->agent->recordFailedPin()) === 0) {
                Audit::record('agent.locked', "Agent {$this->agent->name} ({$this->agent->phone_number}) locked after too many wrong PINs", $this->agent, actor: 'USSD');

                return "Too many wrong PINs.\nTry again in ".config('election.pin_lock_minutes').' minutes.';
            }

            return $this->fail(self::ERROR_PIN);
        }

        $this->agent->clearFailedPins();

        $result = $this->recorder->submitResult(
            $this->agent,
            $this->data['pu'],
            $this->data['accredited'],
            $this->data['votes'],
            $this->data['rejected'],
            correction: $this->data['correction'],
        );

        if ($result === null) {
            return 'Result already submitted for this PU.';
        }

        return $result->isCorrection()
            ? "Correction sent for review ✔\nRef: {$result->reference}"
            : "Submitted ✔\nRef: {$result->reference}";
    }

    // Flow 2: Report Incident ----------------------------------------------

    private function onIncidentType(string $input): ?string
    {
        $type = IncidentType::fromMenuOption($input);

        if ($type === null) {
            return $this->fail(self::ERROR_OPTION);
        }

        $this->data['type'] = $type;

        if ($this->agent->hasAssignedPollingUnit()) {
            $this->data['pu'] = $this->agent->polling_unit_code;
            $this->data['unit'] = PollingUnit::findByCode($this->agent->polling_unit_code);

            return $this->goTo(self::INCIDENT_NOTE);
        }

        return $this->goTo(self::INCIDENT_PU);
    }

    private function onIncidentPollingUnit(string $input): ?string
    {
        return $this->withPollingUnit($input, function (string $code, ?PollingUnit $unit) {
            $this->data['pu'] = $code;
            $this->data['unit'] = $unit;

            return $this->goTo(self::INCIDENT_NOTE);
        });
    }

    private function onIncidentNote(string $input): ?string
    {
        if ($input === '' || mb_strlen($input) > config('ussd.max_note_length')) {
            return $this->fail(self::ERROR_NOTE);
        }

        $this->data['note'] = $input;

        return $this->goTo(self::INCIDENT_CONFIRM);
    }

    private function onIncidentConfirm(string $input): ?string
    {
        switch ($input) {
            case '1':
                $incident = $this->recorder->logIncident(
                    $this->agent,
                    $this->data['pu'],
                    $this->data['type'],
                    $this->data['note'],
                );

                return "Incident Logged ✔\nRef: {$incident->reference}";
            case '2':
                return 'Report cancelled.';
            default:
                return $this->fail(self::ERROR_OPTION);
        }
    }

    // Flow 3: Confirm Presence ---------------------------------------------

    private function startPresence(): ?string
    {
        if (! $this->agent->hasAssignedPollingUnit()) {
            return $this->goTo(self::PRESENCE_PU);
        }

        return $this->confirmPresence($this->agent->polling_unit_code, PollingUnit::findByCode($this->agent->polling_unit_code));
    }

    private function onPresencePollingUnit(string $input): ?string
    {
        return $this->withPollingUnit($input, fn (string $code, ?PollingUnit $unit) => $this->confirmPresence($code, $unit));
    }

    private function confirmPresence(string $code, ?PollingUnit $unit): string
    {
        $this->recorder->confirmPresence($this->agent, $code);

        return 'Presence Confirmed ✔'.($unit ? "\n".$unit->shortName(30) : '');
    }

    // Helpers ---------------------------------------------------------------

    private function goTo(string $state): null
    {
        $this->state = $state;

        return null;
    }

    /**
     * Stay on the current screen and show an error above its prompt.
     */
    private function fail(string $error): null
    {
        $this->error = $error;

        return null;
    }

    /**
     * Validate a typed PU code against the format and the PU register, then
     * hand it on, or stay on the screen with an error.
     *
     * @param  callable(string, ?PollingUnit): ?string  $next
     */
    private function withPollingUnit(string $input, callable $next): ?string
    {
        if (! ctype_digit($input)) {
            return $this->fail(self::ERROR_NUMBER);
        }

        if (! preg_match(config('ussd.polling_unit_pattern'), $input)) {
            return $this->fail(self::ERROR_PU);
        }

        $unit = PollingUnit::findByCode($input);

        if ($unit === null && config('election.require_known_polling_unit')) {
            return $this->fail(self::ERROR_PU_UNKNOWN);
        }

        return $next($input, $unit);
    }

    private function isCount(string $input): bool
    {
        return ctype_digit($input) && strlen($input) <= config('ussd.max_vote_digits');
    }

    /**
     * @return list<string>
     */
    private function parties(): array
    {
        return config('election.parties');
    }

    private function currentParty(): string
    {
        return $this->parties()[count($this->data['votes'])];
    }

    private function validVotes(): int
    {
        return array_sum($this->data['votes']);
    }

    private function votesCast(): int
    {
        return $this->validVotes() + $this->data['rejected'];
    }

    private function screen(): string
    {
        return match ($this->error) {
            self::ERROR_NUMBER => "Invalid input.\nEnter number only:",
            self::ERROR_PU => "Invalid PU code.\nEnter PU Code:",
            self::ERROR_PU_UNKNOWN => "PU code not found.\nEnter PU Code:",
            self::ERROR_ABOVE_REGISTERED => "Error:\nAccredited cannot exceed\nregistered voters ({$this->data['unit']->registered_voters}).\nRe-enter accredited:",
            self::ERROR_NOTE => 'Invalid input.'
                ."\nNote must be 1-".config('ussd.max_note_length').' characters:',
            self::ERROR_PIN => isset($this->data['pin_tries_left'])
                ? "Wrong PIN. {$this->data['pin_tries_left']} tries left.\nEnter PIN:"
                : "Wrong PIN.\nEnter PIN:",
            self::ERROR_OPTION => "Invalid input.\n".$this->prompt(),
            null => $this->prompt(),
        };
    }

    private function prompt(): string
    {
        return match ($this->state) {
            self::MAIN => (Rehearsal::active() ? 'Election Shield REHEARSAL' : 'Election Shield')."\n1. Submit Result\n2. Report Incident\n3. Confirm Presence\n4. Instructions\n5. Exit",
            self::RESULT_PU, self::INCIDENT_PU, self::PRESENCE_PU => 'Enter PU Code:',
            self::RESULT_EXISTS => "Result already submitted\nfor this PU.\n1. Request correction\n2. Exit",
            self::RESULT_ACCREDITED => $this->unitLine().($this->data['correction'] ? "CORRECTION\n" : '').'Accredited Voters:',
            self::RESULT_PARTY => "Votes for {$this->currentParty()}:",
            self::RESULT_REJECTED => 'Rejected Votes:',
            self::RESULT_OVER => "Error:\nVotes cast ({$this->votesCast()}) exceed\naccredited ({$this->data['accredited']}).\n1. Re-enter accredited\n2. Start again",
            self::RESULT_ACCREDITED_FIX => 'Re-enter accredited:',
            self::RESULT_CONFIRM => $this->resultConfirmation(),
            self::RESULT_PIN => 'Enter PIN to submit:',
            self::INCIDENT_TYPE => "Incident Type:\n1. Violence\n2. Vote Buying\n3. Delay\n4. Other",
            self::INCIDENT_NOTE => 'Short Note:',
            self::INCIDENT_CONFIRM => "Confirm Incident:\n{$this->data['type']->label()}\n".$this->unitLine()."PU:{$this->data['pu']}\n1. Submit\n2. Cancel",
        };
    }

    private function unitLine(): string
    {
        return isset($this->data['unit']) ? $this->data['unit']->shortName()."\n" : '';
    }

    private function resultConfirmation(): string
    {
        $partyLines = array_map(
            fn (array $pair) => implode(' ', $pair),
            array_chunk(array_map(
                fn (string $party, int $votes) => "{$party}:{$votes}",
                array_keys($this->data['votes']),
                $this->data['votes'],
            ), 2),
        );

        return implode("\n", [
            $this->data['correction'] ? 'Confirm CORRECTION:' : 'Confirm:',
            "PU:{$this->data['pu']}",
            ...(isset($this->data['unit']) ? [$this->data['unit']->shortName()] : []),
            "Acc:{$this->data['accredited']} Rej:{$this->data['rejected']}",
            ...$partyLines,
            "Valid:{$this->validVotes()}",
            '1.Submit 2.Edit 3.Cancel',
        ]);
    }
}
