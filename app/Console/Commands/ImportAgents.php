<?php

namespace App\Console\Commands;

use App\Services\AgentImporter;
use Illuminate\Console\Command;

class ImportAgents extends Command
{
    protected $signature = 'agent:import
        {file : CSV with a header row: name,phone[,pu_code][,pin]}
        {--sms-pins : Text each new PIN to its agent}';

    protected $description = 'Register or update agents in bulk from a CSV file';

    public function handle(AgentImporter $importer): int
    {
        $report = $importer->import($this->argument('file'), (bool) $this->option('sms-pins'));

        foreach ($report['errors'] as $error) {
            $this->warn($error);
        }

        $this->table(
            ['Name', 'Phone', 'PU', 'New PIN'],
            array_map(fn ($row) => [$row['name'], $row['phone_number'], $row['polling_unit_code'] ?? '—', $row['pin'] ?? '(unchanged)'], $report['imported']),
        );

        $this->info(count($report['imported']).' agent(s) imported; '.count($report['errors']).' row(s) skipped.');

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
