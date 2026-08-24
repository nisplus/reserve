<?php

declare(strict_types=1);

namespace App\Service;

/**
 * CSV for the admin export, shaped for the one client that matters: Excel.
 *
 *   - UTF-8 BOM up front, or Excel guesses CP932 and mangles every kanji.
 *   - CRLF row endings (fputcsv's eol parameter, PHP 8.1+).
 *   - Formula injection defused: a cell starting with = + - @ or a tab/CR
 *     would execute as a formula when the file is opened; names and event
 *     titles are user input, so each such cell gets a leading apostrophe.
 */
final class CsvExporter
{
    /**
     * @param array<int, string>             $header
     * @param array<int, array<int, mixed>>  $rows
     */
    public function build(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, $header, ',', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            fputcsv($stream, array_map(self::defuse(...), $row), ',', '"', '\\', "\r\n");
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    private static function defuse(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && (str_contains('=+-@', $text[0]) || $text[0] === "\t" || $text[0] === "\r")) {
            return "'" . $text;
        }
        return $text;
    }
}
