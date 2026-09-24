<?php

namespace App\Console\Commands;

use App\Models\PollingUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPollingUnits extends Command
{
    protected $signature = 'pu:import
        {file : CSV with a header row: code,name,ward,lga[,registered_voters]}';

    protected $description = 'Import or update the INEC polling unit register from a CSV file';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle, escape: '') ?: []);
        $missing = array_diff(['code', 'name', 'ward', 'lga'], $header);

        if ($missing !== []) {
            $this->error('CSV is missing column(s): '.implode(', ', $missing));

            return self::FAILURE;
        }

        $imported = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, &$imported, &$errors, &$line) {
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $line++;

                if ($row === [null] || count($row) !== count($header)) {
                    $row === [null] || $errors[] = "Line {$line}: expected ".count($header).' columns';

                    continue;
                }

                $data = array_map('trim', array_combine($header, $row));
                $code = PollingUnit::normalizeCode($data['code']);

                if (! preg_match(config('ussd.polling_unit_pattern'), $code) || $data['name'] === '' || $data['lga'] === '') {
                    $errors[] = "Line {$line}: invalid code, name or LGA";

                    continue;
                }

                $registered = $data['registered_voters'] ?? '';

                PollingUnit::updateOrCreate(['code' => $code], [
                    'name' => $data['name'],
                    'ward' => $data['ward'],
                    'lga' => $data['lga'],
                    'registered_voters' => ctype_digit($registered) ? (int) $registered : null,
                ]);

                $imported++;
            }
        });

        fclose($handle);

        foreach ($errors as $error) {
            $this->warn($error);
        }

        $this->info("Imported {$imported} polling unit(s); ".count($errors).' row(s) skipped. Register now has '.PollingUnit::count().'.');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
