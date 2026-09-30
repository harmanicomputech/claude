<?php

namespace App\Ussd;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Enums\VolunteerRole;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Services\ElectionRecorder;
use App\Support\Audit;
use App\Support\ElectionCalendar;
use App\Support\PhoneNumber;
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
 *
 * Anyone may dial: registered agents get the full menu; everyone else (the
 * public) can report an incident, sign up to help, and read election info.
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

    private const MATERIALS_PU = 'materials.pu';

    private const MATERIALS_STATUS = 'materials.status';

    // Choosing an LGA, then a ward (for a public incident or a volunteer).
    private const PICK_LGA = 'pick.lga';

    private const PICK_WARD = 'pick.ward';

    private const PUBLIC_PU = 'public.pu';

    private const VOLUNTEER_ROLES = 'volunteer.roles';

    private const VOLUNTEER_SKILLS = 'volunteer.skills';

    private const VOLUNTEER_OTHER = 'volunteer.other';

    private const VOLUNTEER_NAME = 'volunteer.name';

    private const VOLUNTEER_PHONE = 'volunteer.phone';

    private const VOLUNTEER_PHONE_ENTER = 'volunteer.phone_enter';

    private const VOLUNTEER_CONFIRM = 'volunteer.confirm';

    private const ERROR_NUMBER = 'number';

    private const ERROR_OPTION = 'option';

    private const ERROR_PU = 'pu';

    private const ERROR_PU_UNKNOWN = 'pu_unknown';

    private const ERROR_ABOVE_REGISTERED = 'above_registered';

    private const ERROR_NOTE = 'note';

    private const ERROR_PIN = 'pin';

    private const ERROR_CHOICES = 'choices';

    private const ERROR_NAME = 'name';

    private const ERROR_PHONE = 'phone';

    /** A USSD screen holds about 182 characters, "CON " included. */
    private const SCREEN_CHARS = 182;

    /** Null for a member of the public. */
    private ?Agent $agent;

    /** The caller's number (E.164). */
    private string $phone;

    /** @var array<string, list<string>> */
    private array $placeCache = [];

    private string $state;

    /** @var array<string, mixed> */
    private array $data;

    private ?string $error;

    private bool $isLatestInput;

    public function __construct(
        private ElectionRecorder $recorder,
        private ElectionCalendar $calendar,
    ) {}

    public function handle(?Agent $agent, string $phone, string $text): string
    {
        if ($agent?->isLocked()) {
            return "END Account locked.\nTry again later or\ncontact coordinator.";
        }

        $this->agent = $agent;
        $this->phone = $phone;
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
            self::MATERIALS_PU => $this->onMaterialsPollingUnit($input),
            self::MATERIALS_STATUS => $this->onMaterialsStatus($input),
            self::PICK_LGA => $this->onPickLga($input),
            self::PICK_WARD => $this->onPickWard($input),
            self::PUBLIC_PU => $this->onPublicPollingUnit($input),
            self::VOLUNTEER_ROLES => $this->onVolunteerRoles($input),
            self::VOLUNTEER_SKILLS => $this->onVolunteerSkills($input),
            self::VOLUNTEER_OTHER => $this->onVolunteerOther($input),
            self::VOLUNTEER_NAME => $this->onVolunteerName($input),
            self::VOLUNTEER_PHONE => $this->onVolunteerPhone($input),
            self::VOLUNTEER_PHONE_ENTER => $this->onVolunteerPhoneEnter($input),
            self::VOLUNTEER_CONFIRM => $this->onVolunteerConfirm($input),
        };
    }

    private function onMainMenu(string $input): ?string
    {
        if ($this->agent === null) {
            return match ($input) {
                '1' => $this->startPublicIncident(),
                '2' => $this->startVolunteer(),
                '3' => (string) config('ussd.public_info'),
                '4' => 'Thank you',
                default => $this->fail(self::ERROR_OPTION),
            };
        }

        return match ($input) {
            '1' => $this->calendar->resultsClosedMessage() ?? $this->startResult(correction: false),
            '2' => $this->goTo(self::INCIDENT_TYPE),
            '3' => $this->calendar->presenceClosedMessage() ?? $this->startPresence(),
            '4' => $this->startMaterials(),
            '5' => (string) config('ussd.instructions'),
            '6' => $this->startVolunteer(),
            '7' => 'Thank you',
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

        if ($this->agent === null) {
            return $this->startPicking('incident');
        }

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
        if ($this->agent === null) {
            return $this->onPublicIncidentConfirm($input);
        }

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

    // Flow 4: Materials status ---------------------------------------------

    private function startMaterials(): ?string
    {
        if (! $this->agent->hasAssignedPollingUnit()) {
            return $this->goTo(self::MATERIALS_PU);
        }

        $this->data['pu'] = $this->agent->polling_unit_code;
        $this->data['unit'] = PollingUnit::findByCode($this->agent->polling_unit_code);

        return $this->goTo(self::MATERIALS_STATUS);
    }

    private function onMaterialsPollingUnit(string $input): ?string
    {
        return $this->withPollingUnit($input, function (string $code, ?PollingUnit $unit) {
            $this->data['pu'] = $code;
            $this->data['unit'] = $unit;

            return $this->goTo(self::MATERIALS_STATUS);
        });
    }

    private function onMaterialsStatus(string $input): ?string
    {
        $status = MaterialStatus::fromMenuOption($input);

        if ($status === null) {
            return $this->fail(self::ERROR_OPTION);
        }

        $this->recorder->reportMaterials($this->agent, $this->data['pu'], $status);

        return "Materials report saved ✔\n{$status->label()}".(isset($this->data['unit']) ? "\n".$this->data['unit']->shortName(30) : '');
    }

    // Public incident (anyone who is not a registered agent) -------------

    private function startPublicIncident(): ?string
    {
        if ($this->publicLimitReached()) {
            return "Daily report limit reached.\nThank you for reporting.\nTry again tomorrow.";
        }

        $this->data = ['public' => true];

        return $this->goTo(self::INCIDENT_TYPE);
    }

    private function publicLimitReached(): bool
    {
        return $this->recorder->publicReportsToday($this->phone) >= (int) config('ussd.public_daily_incident_limit');
    }

    private function onPublicPollingUnit(string $input): ?string
    {
        if ($input === '0') {
            $this->data['unit'] = null;

            return $this->goTo(self::INCIDENT_NOTE);
        }

        return $this->withPollingUnit($input, function (string $code, ?PollingUnit $unit) {
            $this->data['unit'] = $unit;
            $this->data['pu'] = $code;

            return $this->goTo(self::INCIDENT_NOTE);
        });
    }

    private function onPublicIncidentConfirm(string $input): ?string
    {
        switch ($input) {
            case '1':
                if ($this->publicLimitReached()) {
                    return "Daily report limit reached.\nThank you for reporting.";
                }

                $incident = $this->recorder->logPublicIncident(
                    $this->phone,
                    $this->data['lga'],
                    $this->data['ward'],
                    $this->data['unit'] ?? null,
                    $this->data['type'],
                    $this->data['note'],
                );

                return "Report received ✔\nThank you.\nRef: {$incident->reference}";
            case '2':
                return 'Report cancelled.';
            default:
                return $this->fail(self::ERROR_OPTION);
        }
    }

    // How can you help? (volunteers; agents and the public) ---------------

    private function startVolunteer(): ?string
    {
        if ($this->recorder->volunteerSignUpsToday($this->phone) >= (int) config('ussd.volunteer_daily_limit')) {
            return "Daily sign-up limit reached\nfor this phone.\nThank you! Try again tomorrow.";
        }

        return $this->goTo(self::VOLUNTEER_ROLES);
    }

    private function onVolunteerRoles(string $input): ?string
    {
        $roles = $this->choices($input, VolunteerRole::menu());

        if ($roles === null) {
            return $this->fail(self::ERROR_CHOICES);
        }

        $this->data = ['roles' => $roles];

        if (in_array(VolunteerRole::Professional, $roles, true)) {
            return $this->goTo(self::VOLUNTEER_SKILLS);
        }

        return $this->afterVolunteerSkills();
    }

    private function onVolunteerSkills(string $input): ?string
    {
        $skills = $this->choices($input, array_keys(VolunteerRole::SKILLS));

        if ($skills === null) {
            return $this->fail(self::ERROR_CHOICES);
        }

        $this->data['skills'] = $skills;

        return $this->afterVolunteerSkills();
    }

    private function afterVolunteerSkills(): ?string
    {
        if (in_array(VolunteerRole::Other, $this->data['roles'], true)) {
            return $this->goTo(self::VOLUNTEER_OTHER);
        }

        return $this->startPicking('volunteer');
    }

    private function onVolunteerOther(string $input): ?string
    {
        if (mb_strlen($input) > config('ussd.max_volunteer_note_length')) {
            return $this->fail(self::ERROR_NOTE);
        }

        $this->data['other'] = $input === '' ? null : $input;

        return $this->startPicking('volunteer');
    }

    private function onVolunteerName(string $input): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', $input));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || ! preg_match('/\p{L}/u', $name) || preg_match('/\d/', $name)) {
            return $this->fail(self::ERROR_NAME);
        }

        $this->data['name'] = $name;

        return $this->goTo(self::VOLUNTEER_PHONE);
    }

    private function onVolunteerPhone(string $input): ?string
    {
        return match ($input) {
            '1' => $this->volunteerContact($this->phone),
            '2' => $this->goTo(self::VOLUNTEER_PHONE_ENTER),
            default => $this->fail(self::ERROR_OPTION),
        };
    }

    private function onVolunteerPhoneEnter(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input);
        $number = $digits === '' ? '' : PhoneNumber::normalize($input);

        if (! preg_match('/^\+234[789][01]\d{8}$/', $number)) {
            return $this->fail(self::ERROR_PHONE);
        }

        return $this->volunteerContact($number);
    }

    private function volunteerContact(string $number): null
    {
        $this->data['contact'] = $number;

        return $this->goTo(self::VOLUNTEER_CONFIRM);
    }

    private function onVolunteerConfirm(string $input): ?string
    {
        switch ($input) {
            case '1':
                $volunteer = $this->recorder->registerVolunteer(
                    $this->phone,
                    $this->data['contact'],
                    $this->data['name'],
                    $this->data['lga'],
                    $this->data['ward'],
                    array_map(fn (VolunteerRole $role) => $role->value, $this->data['roles']),
                    $this->data['skills'] ?? [],
                    $this->data['other'] ?? null,
                    $this->agent !== null,
                );
                $first = strtok($volunteer->name, ' ');

                return "Thank you, {$first}! ✔\nWe will contact you on\n".$this->localNumber($volunteer->contact_phone).".\nRef: {$volunteer->reference}";
            case '2':
                return 'Cancelled.';
            default:
                return $this->fail(self::ERROR_OPTION);
        }
    }

    // Choosing an LGA, then a ward -----------------------------------------

    private function startPicking(string $for): null
    {
        $this->data['pick_for'] = $for;
        $this->data['page'] = 0;

        return $this->goTo(self::PICK_LGA);
    }

    private function onPickLga(string $input): ?string
    {
        return $this->pick($input, $this->lgas(), $this->lgaHeader(), function (string $lga) {
            $this->data['lga'] = $lga;
            $this->data['page'] = 0;

            return $this->goTo(self::PICK_WARD);
        });
    }

    private function onPickWard(string $input): ?string
    {
        return $this->pick($input, $this->wards($this->data['lga']), $this->wardHeader(), function (string $ward) {
            $this->data['ward'] = $ward;

            return $this->data['pick_for'] === 'incident'
                ? $this->goTo(self::PUBLIC_PU)
                : $this->goTo(self::VOLUNTEER_NAME);
        });
    }

    /**
     * "0" shows the next page; a number picks that item (numbers run on
     * across pages, so any number in the list works on any page).
     *
     * @param  list<string>  $items
     * @param  callable(string): ?string  $next
     */
    private function pick(string $input, array $items, string $header, callable $next): ?string
    {
        $pages = count($this->pages($this->state === self::PICK_WARD ? $this->wardLabels() : $items, $header));

        if ($input === '0' && $pages > 1) {
            $this->data['page'] = ($this->data['page'] + 1) % $pages;

            return null;
        }

        if (! ctype_digit($input) || (int) $input < 1 || (int) $input > count($items)) {
            return $this->fail(self::ERROR_OPTION);
        }

        return $next($items[(int) $input - 1]);
    }

    /**
     * @return list<string>
     */
    private function lgas(): array
    {
        return $this->placeCache['lgas'] ??= PollingUnit::query()->whereNotNull('lga')->where('lga', '!=', '')->distinct()->orderBy('lga')->pluck('lga')->all();
    }

    /**
     * @return list<string>
     */
    private function wards(string $lga): array
    {
        return $this->placeCache['wards:'.$lga] ??= PollingUnit::query()->where('lga', $lga)->whereNotNull('ward')->distinct()->orderBy('ward')->pluck('ward')->all();
    }

    private function lgaHeader(): string
    {
        return $this->data['pick_for'] === 'incident' ? 'Where? Choose LGA:' : 'Your LGA:';
    }

    private function wardHeader(): string
    {
        return mb_strimwidth($this->data['lga'], 0, 20, '').': choose ward';
    }

    /**
     * @return list<string>
     */
    private function wardLabels(): array
    {
        return array_map(fn (string $ward) => $this->wardLabel($ward, $this->data['lga']), $this->wards($this->data['lga']));
    }

    /**
     * "Abakaliki Ward 01" → "Ward 01" (the LGA is already on the screen).
     */
    private function wardLabel(string $ward, string $lga): string
    {
        $short = str_starts_with($ward, $lga.' ') ? substr($ward, strlen($lga) + 1) : $ward;

        return mb_strimwidth($short, 0, 22, '');
    }

    /**
     * Split a list into screens that fit: [[first index, last index], ...].
     *
     * @param  list<string>  $labels
     * @return list<array{0: int, 1: int}>
     */
    private function pages(array $labels, string $header): array
    {
        // Room left after "CON ", an error line ("Invalid input.") and "0.Back to start".
        $budget = self::SCREEN_CHARS - 4 - 15 - (mb_strlen($header) + 1) - 16;
        $pages = [];
        $start = 0;
        $used = 0;

        foreach ($labels as $i => $label) {
            $line = mb_strlen(($i + 1).'.'.$label) + 1;
            if ($i > $start && $used + $line > $budget) {
                $pages[] = [$start, $i - 1];
                $start = $i;
                $used = 0;
            }
            $used += $line;
        }

        $pages[] = [$start, max($start, count($labels) - 1)];

        return $pages;
    }

    /**
     * @param  list<string>  $labels
     */
    private function pickScreen(string $header, array $labels): string
    {
        $pages = $this->pages($labels, $header);
        [$first, $last] = $pages[min($this->data['page'], count($pages) - 1)];
        $lines = [];
        for ($i = $first; $i <= $last && $i < count($labels); $i++) {
            $lines[] = ($i + 1).'.'.$labels[$i];
        }
        if (count($pages) > 1) {
            $lines[] = $last < count($labels) - 1 ? '0.More' : '0.Back to start';
        }

        return $header."\n".implode("\n", $lines);
    }

    /**
     * Several options typed together, e.g. "135". Null when any is invalid.
     *
     * @template T
     *
     * @param  list<T>  $options
     * @return list<T>|null
     */
    private function choices(string $input, array $options): ?array
    {
        if ($input === '' || ! ctype_digit($input)) {
            return null;
        }

        $picked = [];
        foreach (str_split($input) as $digit) {
            $option = $options[(int) $digit - 1] ?? null;
            if ($digit === '0' || $option === null) {
                return null;
            }
            $picked[(int) $digit] = $option;
        }
        ksort($picked);

        return array_values($picked);
    }

    private function localNumber(string $number): string
    {
        return str_starts_with($number, '+234') ? '0'.substr($number, 4) : $number;
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
        $puPrompt = $this->state === self::PUBLIC_PU ? 'Enter PU code or 0 to skip:' : 'Enter PU Code:';

        return match ($this->error) {
            self::ERROR_NUMBER => "Invalid input.\n".($this->state === self::PUBLIC_PU ? $puPrompt : 'Enter number only:'),
            self::ERROR_PU => "Invalid PU code.\n{$puPrompt}",
            self::ERROR_PU_UNKNOWN => "PU code not found.\n{$puPrompt}",
            self::ERROR_ABOVE_REGISTERED => "Error:\nAccredited cannot exceed\nregistered voters ({$this->data['unit']->registered_voters}).\nRe-enter accredited:",
            self::ERROR_NOTE => 'Invalid input.'
                ."\nNote must be ".($this->state === self::VOLUNTEER_OTHER ? 'up to '.config('ussd.max_volunteer_note_length') : '1-'.config('ussd.max_note_length')).' characters:',
            self::ERROR_PIN => isset($this->data['pin_tries_left'])
                ? "Wrong PIN. {$this->data['pin_tries_left']} tries left.\nEnter PIN:"
                : "Wrong PIN.\nEnter PIN:",
            self::ERROR_CHOICES => $this->state === self::VOLUNTEER_SKILLS
                ? "Invalid. Type e.g. 13:\n".$this->skillsList()
                : "Invalid. Type e.g. 135:\n".$this->rolesList(),
            self::ERROR_NAME => "Invalid name.\nEnter your full name:",
            self::ERROR_PHONE => "Invalid number.\nEnter phone number\n(e.g. 08031234567):",
            self::ERROR_OPTION => "Invalid input.\n".$this->prompt(),
            null => $this->prompt(),
        };
    }

    private function prompt(): string
    {
        return match ($this->state) {
            self::MAIN => (Rehearsal::active() ? 'Election Shield REHEARSAL' : 'Election Shield')."\n".($this->agent
                ? "1. Submit Result\n2. Report Incident\n3. Confirm Presence\n4. Materials Status\n5. Instructions\n6. How can you help?\n7. Exit"
                : "1. Report Incident\n2. How can you help?\n3. Election info\n4. Exit"),
            self::RESULT_PU, self::INCIDENT_PU, self::PRESENCE_PU, self::MATERIALS_PU => 'Enter PU Code:',
            self::RESULT_EXISTS => "Result already submitted\nfor this PU.\n1. Request correction\n2. Exit",
            self::RESULT_ACCREDITED => $this->unitLine().($this->data['correction'] ? "CORRECTION\n" : '').'Accredited Voters:',
            self::RESULT_PARTY => "Votes for {$this->currentParty()}:",
            self::RESULT_REJECTED => 'Rejected Votes:',
            self::RESULT_OVER => "Error:\nVotes cast ({$this->votesCast()}) exceed\naccredited ({$this->data['accredited']}).\n1. Re-enter accredited\n2. Start again",
            self::RESULT_ACCREDITED_FIX => 'Re-enter accredited:',
            self::RESULT_CONFIRM => $this->resultConfirmation(),
            self::RESULT_PIN => 'Enter PIN to submit:',
            self::INCIDENT_TYPE => "Incident Type:\n".$this->numbered(array_map(fn (IncidentType $type) => $type->label(), IncidentType::menu())),
            self::MATERIALS_STATUS => $this->unitLine()."Materials Status:\n".$this->numbered(array_map(fn (MaterialStatus $status) => $status->label(), MaterialStatus::menu())),
            self::INCIDENT_NOTE => 'Short Note:',
            self::INCIDENT_CONFIRM => $this->agent
                ? "Confirm Incident:\n{$this->data['type']->label()}\n".$this->unitLine()."PU:{$this->data['pu']}\n1. Submit\n2. Cancel"
                : "Confirm Report:\n{$this->data['type']->label()}\n".$this->wardLabel($this->data['ward'], $this->data['lga']).", {$this->data['lga']}\n".(isset($this->data['unit']) ? $this->unitLine() : '')."1. Submit\n2. Cancel",
            self::PICK_LGA => $this->pickScreen($this->lgaHeader(), $this->lgas()),
            self::PICK_WARD => $this->pickScreen($this->wardHeader(), $this->wardLabels()),
            self::PUBLIC_PU => "PU code (if you know it)\nor 0 to skip:",
            self::VOLUNTEER_ROLES => "How can you help?\nChoose any, e.g. 135\n".$this->rolesList(),
            self::VOLUNTEER_SKILLS => "Which skills?\nChoose any, e.g. 13\n".$this->skillsList(),
            self::VOLUNTEER_OTHER => "Anything else?\nTell us how you can help:",
            self::VOLUNTEER_NAME => 'Your full name:',
            self::VOLUNTEER_PHONE => "Contact number:\n".$this->localNumber($this->phone)."\n1. Use this number\n2. Use another number",
            self::VOLUNTEER_PHONE_ENTER => "Enter phone number\n(e.g. 08031234567):",
            self::VOLUNTEER_CONFIRM => $this->volunteerConfirmation(),
        };
    }

    /**
     * @param  list<string>  $options
     */
    private function numbered(array $options, string $separator = '. '): string
    {
        return implode("\n", array_map(fn (string $option, int $i) => ($i + 1).$separator.$option, $options, array_keys($options)));
    }

    private function rolesList(): string
    {
        return $this->numbered(array_map(fn (VolunteerRole $role) => $role->shortLabel(), VolunteerRole::menu()), '.');
    }

    private function skillsList(): string
    {
        return $this->numbered(array_values(VolunteerRole::SKILLS), '.');
    }

    private function volunteerConfirmation(): string
    {
        $help = implode(', ', array_map(fn (VolunteerRole $role) => $role->shortLabel(), $this->data['roles']));

        return implode("\n", [
            'Confirm:',
            mb_strimwidth($this->data['name'], 0, 28, '…'),
            $this->wardLabel($this->data['ward'], $this->data['lga']).', '.$this->data['lga'],
            $this->localNumber($this->data['contact']),
            mb_strimwidth('Help: '.$help, 0, 70, '…'),
            '1. Submit',
            '2. Cancel',
        ]);
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
