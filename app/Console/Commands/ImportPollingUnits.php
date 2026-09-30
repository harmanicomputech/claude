<?php

namespace App\Console\Commands;

use App\Models\PollingUnit;
use App\Services\PollingUnitRegister;
use Illuminate\Console\Command;
use RuntimeException;

class ImportPollingUnits extends Command
{
    protected $signature = 'pu:import
        {file : CSV with a header row: code,name,ward,lga[,registered_voters]}
        {--replace : Remove PUs that are not in the file (agents assigned to them are unassigned)}';

    protected $description = 'Import or update the INEC polling unit register from a CSV file';

    public function handle(PollingUnitRegister $register): int
    {
        try {
            $report = $register->import($this->argument('file'), (bool) $this->option('replace'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report['errors'] as $error) {
            $this->warn($error);
        }
        foreach ($report['unassigned'] as $agent) {
            $this->warn("Unassigned agent: {$agent}");
        }

        $this->info("Imported {$report['imported']} polling unit(s); ".count($report['errors']).' row(s) skipped'
            .($this->option('replace') ? "; {$report['removed']} removed; ".count($report['unassigned']).' agent(s) unassigned' : '')
            .'. Register now has '.PollingUnit::count().'.');

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
