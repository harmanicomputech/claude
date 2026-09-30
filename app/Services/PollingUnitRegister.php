<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\PollingUnit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the polling unit register from CSV (code,name,ward,lga and
 * optionally registered_voters; other columns are ignored).
 *
 * An empty registered_voters keeps the figure already stored, so loading
 * names again never wipes voter numbers. With $replace, PUs missing from the
 * file are removed and agents assigned to them are unassigned.
 */
class PollingUnitRegister
{
    /**
     * @return array{imported: int, errors: list<string>, removed: int, unassigned: list<string>}
     */
    public function import(string $path, bool $replace = false): array
    {
        if (! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        try {
            $header = array_map(fn ($column) => strtolower(trim((string) $column, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle, escape: '') ?: []);
            $missing = array_diff(['code', 'name', 'ward', 'lga'], $header);
            if ($missing !== []) {
                throw new RuntimeException('CSV is missing column(s): '.implode(', ', $missing));
            }

            $units = [];
            $errors = [];
            $line = 1;
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($header)) {
                    $errors[] = "Line {$line}: expected ".count($header).' columns';

                    continue;
                }

                $data = array_map('trim', array_combine($header, $row));
                $code = PollingUnit::normalizeCode($data['code']);
                if (! preg_match(config('ussd.polling_unit_pattern'), $code) || $data['name'] === '' || $data['lga'] === '') {
                    $errors[] = "Line {$line}: invalid code, name or LGA";

                    continue;
                }

                $registered = $data['registered_voters'] ?? '';
                $units[$code] = [
                    'code' => $code,
                    'name' => $data['name'],
                    'ward' => $data['ward'] === '' ? null : $data['ward'],
                    'lga' => $data['lga'],
                    'registered_voters' => ctype_digit($registered) ? (int) $registered : null,
                ];
            }
        } finally {
            fclose($handle);
        }

        if ($replace && ($errors !== [] || $units === [])) {
            throw new RuntimeException('Not replacing the register: the file has errors or no polling units ('.($errors[0] ?? 'empty').').');
        }

        $removed = 0;
        $unassigned = [];
        DB::transaction(function () use ($units, $replace, &$removed, &$unassigned) {
            $now = now();
            foreach (array_chunk(array_values($units), 500) as $chunk) {
                // Rows without a voter figure leave the stored one alone.
                foreach ([true, false] as $withVoters) {
                    $rows = array_values(array_filter($chunk, fn ($unit) => ($unit['registered_voters'] !== null) === $withVoters));
                    if ($rows === []) {
                        continue;
                    }
                    $columns = $withVoters ? ['name', 'ward', 'lga', 'registered_voters', 'updated_at'] : ['name', 'ward', 'lga', 'updated_at'];
                    PollingUnit::upsert(array_map(function ($unit) use ($withVoters, $now) {
                        if (! $withVoters) {
                            unset($unit['registered_voters']);
                        }

                        return [...$unit, 'created_at' => $now, 'updated_at' => $now];
                    }, $rows), ['code'], $columns);
                }
            }

            if (! $replace) {
                return;
            }

            $keep = array_keys($units);
            foreach (PollingUnit::query()->pluck('code')->diff($keep)->chunk(500) as $codes) {
                $removed += PollingUnit::whereIn('code', $codes->all())->delete();
            }

            Agent::query()->whereNotNull('polling_unit_code')->orderBy('name')->each(function (Agent $agent) use ($units, &$unassigned) {
                if (! isset($units[$agent->polling_unit_code])) {
                    $unassigned[] = "{$agent->name} ({$agent->phone_number}, was {$agent->polling_unit_code})";
                    $agent->update(['polling_unit_code' => null]);
                }
            });
        });

        return ['imported' => count($units), 'errors' => $errors, 'removed' => $removed, 'unassigned' => $unassigned];
    }
}
