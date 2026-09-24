<?php

namespace App\Ussd;

use App\Enums\IncidentType;
use App\Models\Agent;
use App\Services\ElectionRecorder;

/**
 * Election Shield USSD menu.
 *
 * Africa's Talking sends the whole session so far on every request as a
 * "*"-joined string (e.g. "1*02345*120*300*1"). Rather than reading inputs by
 * position, we replay them through a small state machine. That way invalid
 * inputs, "Edit" and "re-enter total" retries sit naturally in the history,
 * and quick codes like *XXX*1*02345*120*300# land on the right screen.
 *
 * Only terminal (END) steps write to the database, and an END closes the
 * session, so a replay never repeats a write.
 */
class UssdMenu
{
    private const MAIN = 'main';

    private const RESULT_PU = 'result.pu';

    private const RESULT_VOTES = 'result.votes';

    private const RESULT_TOTAL = 'result.total';

    private const RESULT_CONFIRM = 'result.confirm';

    private const INCIDENT_TYPE = 'incident.type';

    private const INCIDENT_PU = 'incident.pu';

    private const INCIDENT_NOTE = 'incident.note';

    private const INCIDENT_CONFIRM = 'incident.confirm';

    private const PRESENCE_PU = 'presence.pu';

    private const ERROR_NUMBER = 'number';

    private const ERROR_OPTION = 'option';

    private const ERROR_PU = 'pu';

    private const ERROR_VOTES_EXCEED_TOTAL = 'votes_exceed_total';

    private const ERROR_NOTE = 'note';

    private Agent $agent;

    private string $state;

    /** @var array<string, mixed> */
    private array $data;

    private ?string $error;

    public function __construct(private ElectionRecorder $recorder) {}

    public function handle(Agent $agent, string $text): string
    {
        $this->agent = $agent;
        $this->state = self::MAIN;
        $this->data = [];
        $this->error = null;

        foreach ($this->inputs($text) as $input) {
            $this->error = null;

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
            self::RESULT_VOTES => $this->onResultVotes($input),
            self::RESULT_TOTAL => $this->onResultTotal($input),
            self::RESULT_CONFIRM => $this->onResultConfirm($input),
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
            '1' => $this->startResult(),
            '2' => $this->goTo(self::INCIDENT_TYPE),
            '3' => $this->startPresence(),
            '4' => (string) config('ussd.instructions'),
            '5' => 'Thank you',
            default => $this->fail(self::ERROR_OPTION),
        };
    }

    // Flow 1: Submit Result ------------------------------------------------

    private function startResult(): ?string
    {
        $this->data = [];

        if (! $this->agent->hasAssignedPollingUnit()) {
            return $this->goTo(self::RESULT_PU);
        }

        return $this->acceptResultPollingUnit($this->agent->polling_unit_code);
    }

    private function onResultPollingUnit(string $input): ?string
    {
        if (($error = $this->pollingUnitError($input)) !== null) {
            return $this->fail($error);
        }

        return $this->acceptResultPollingUnit($input);
    }

    private function acceptResultPollingUnit(string $pollingUnitCode): ?string
    {
        if ($this->recorder->hasResultFor($pollingUnitCode)) {
            return 'Result already submitted for this PU.';
        }

        $this->data['pu'] = $pollingUnitCode;

        return $this->goTo(self::RESULT_VOTES);
    }

    private function onResultVotes(string $input): ?string
    {
        if (! $this->isCount($input)) {
            return $this->fail(self::ERROR_NUMBER);
        }

        $this->data['votes'] = (int) $input;

        return $this->goTo(self::RESULT_TOTAL);
    }

    private function onResultTotal(string $input): ?string
    {
        if (! $this->isCount($input)) {
            return $this->fail(self::ERROR_NUMBER);
        }

        if ((int) $input < $this->data['votes']) {
            return $this->fail(self::ERROR_VOTES_EXCEED_TOTAL);
        }

        $this->data['total'] = (int) $input;

        return $this->goTo(self::RESULT_CONFIRM);
    }

    private function onResultConfirm(string $input): ?string
    {
        switch ($input) {
            case '1':
                $result = $this->recorder->submitResult(
                    $this->agent,
                    $this->data['pu'],
                    $this->data['votes'],
                    $this->data['total'],
                );

                return $result === null
                    ? 'Result already submitted for this PU.'
                    : "Submitted ✔\nRef: {$result->reference}";
            case '2':
                return $this->startResult();
            case '3':
                return 'Submission cancelled.';
            default:
                return $this->fail(self::ERROR_OPTION);
        }
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

            return $this->goTo(self::INCIDENT_NOTE);
        }

        return $this->goTo(self::INCIDENT_PU);
    }

    private function onIncidentPollingUnit(string $input): ?string
    {
        if (($error = $this->pollingUnitError($input)) !== null) {
            return $this->fail($error);
        }

        $this->data['pu'] = $input;

        return $this->goTo(self::INCIDENT_NOTE);
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

        return $this->confirmPresence($this->agent->polling_unit_code);
    }

    private function onPresencePollingUnit(string $input): ?string
    {
        if (($error = $this->pollingUnitError($input)) !== null) {
            return $this->fail($error);
        }

        return $this->confirmPresence($input);
    }

    private function confirmPresence(string $pollingUnitCode): string
    {
        $this->recorder->confirmPresence($this->agent, $pollingUnitCode);

        return 'Presence Confirmed ✔';
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

    private function pollingUnitError(string $input): ?string
    {
        if (! ctype_digit($input)) {
            return self::ERROR_NUMBER;
        }

        return preg_match(config('ussd.polling_unit_pattern'), $input) ? null : self::ERROR_PU;
    }

    private function isCount(string $input): bool
    {
        return ctype_digit($input) && strlen($input) <= config('ussd.max_vote_digits');
    }

    private function screen(): string
    {
        return match ($this->error) {
            self::ERROR_NUMBER => "Invalid input.\nEnter number only:",
            self::ERROR_PU => "Invalid PU code.\nEnter PU Code:",
            self::ERROR_VOTES_EXCEED_TOTAL => "Error:\nVotes cannot exceed total.\nRe-enter total:",
            self::ERROR_NOTE => 'Invalid input.'
                ."\nNote must be 1-".config('ussd.max_note_length').' characters:',
            self::ERROR_OPTION => "Invalid input.\n".$this->prompt(),
            null => $this->prompt(),
        };
    }

    private function prompt(): string
    {
        return match ($this->state) {
            self::MAIN => "Election Shield\n1. Submit Result\n2. Report Incident\n3. Confirm Presence\n4. Instructions\n5. Exit",
            self::RESULT_PU, self::INCIDENT_PU, self::PRESENCE_PU => 'Enter PU Code:',
            self::RESULT_VOTES => 'Votes for Candidate:',
            self::RESULT_TOTAL => 'Total Votes Cast:',
            self::RESULT_CONFIRM => "Confirm:\nPU:{$this->data['pu']}\nVotes:{$this->data['votes']}\nTotal:{$this->data['total']}\n\n1. Submit\n2. Edit\n3. Cancel",
            self::INCIDENT_TYPE => "Incident Type:\n1. Violence\n2. Vote Buying\n3. Delay\n4. Other",
            self::INCIDENT_NOTE => 'Short Note:',
            self::INCIDENT_CONFIRM => "Confirm Incident:\n{$this->data['type']->label()}\nPU:{$this->data['pu']}\n1. Submit\n2. Cancel",
        };
    }
}
