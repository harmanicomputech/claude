<?php

namespace App\Console\Commands;

use App\Services\ElectionStats;
use Illuminate\Console\Command;

class ShowMissing extends Command
{
    protected $signature = 'election:missing
        {type : presence or results}
        {--lga= : Only this LGA}';

    protected $description = 'List registered PUs with no presence check-in today / no accepted result';

    public function handle(ElectionStats $stats): int
    {
        if (! in_array($this->argument('type'), [ElectionStats::MISSING_PRESENCE, ElectionStats::MISSING_RESULTS], true)) {
            $this->error('Type must be "presence" or "results".');

            return self::FAILURE;
        }

        $missing = $stats->missing($this->argument('type'))
            ->when($this->option('lga'), fn ($units, $lga) => $units->where('lga', $lga));

        $this->table(
            ['LGA', 'Ward', 'Code', 'Polling Unit', 'Agents'],
            $missing->map(fn (array $unit) => [
                $unit['lga'],
                $unit['ward'],
                $unit['code'],
                $unit['name'],
                collect($unit['agents'])->map(fn ($agent) => "{$agent['name']} {$agent['phone_number']}")->implode(', ') ?: '—',
            ]),
        );

        $this->info("{$missing->count()} polling unit(s) missing {$this->argument('type')}.");

        return self::SUCCESS;
    }
}
