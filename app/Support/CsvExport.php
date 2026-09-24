<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download that opens cleanly in Excel (UTF-8 BOM) and is
 * safe against spreadsheet formula injection from agent-typed text.
 */
class CsvExport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function filename(string $name): string
    {
        return 'election-shield-'.$name.'-'.now(config('election.timezone'))->format('Y-m-d-Hi').'.csv';
    }

    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        // Phone numbers (+234…) are fine; any other text starting with a
        // formula character is neutralised so Excel shows it as text.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^\+\d+$/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
