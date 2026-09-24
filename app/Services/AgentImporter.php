<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Bulk agent registration from a CSV with a header row:
 * name,phone[,pu_code][,pin]
 */
class AgentImporter
{
    public function __construct(private AgentRegistrar $registrar) {}

    /**
     * @return array{
     *     imported: list<array{name: string, phone_number: string, polling_unit_code: ?string, pin: ?string}>,
     *     errors: list<string>
     * }
     */
    public function import(string $path, bool $smsPins = false): array
    {
        if (! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            return ['imported' => [], 'errors' => ["Cannot read {$path}"]];
        }

        $header = array_map(
            fn ($column) => strtolower(trim((string) $column, " \t\n\r\0\x0B\xEF\xBB\xBF")),
            fgetcsv($handle, escape: '') ?: [],
        );

        $missing = array_diff(['name', 'phone'], $header);

        if ($missing !== []) {
            fclose($handle);

            return ['imported' => [], 'errors' => ['CSV is missing column(s): '.implode(', ', $missing).'. Expected: name,phone,pu_code,pin']];
        }

        $imported = [];
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

            $data = array_combine($header, $row);

            try {
                [$agent, $pin] = $this->registrar->register(
                    $data['phone'],
                    $data['name'],
                    $data['pu_code'] ?? null,
                    $data['pin'] ?? null,
                    $smsPins,
                );
            } catch (InvalidArgumentException $e) {
                $errors[] = "Line {$line}: {$e->getMessage()}";

                continue;
            }

            $imported[] = [
                'name' => $agent->name,
                'phone_number' => $agent->phone_number,
                'polling_unit_code' => $agent->polling_unit_code,
                'pin' => $pin,
            ];
        }

        fclose($handle);

        return ['imported' => $imported, 'errors' => $errors];
    }
}
