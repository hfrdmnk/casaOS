<?php

namespace App\Actions\Finance\Import;

/**
 * Reads the shape and rows of a bank CSV export.
 *
 * A shape is the encoding, the delimiter, the number of preamble lines before the header, and the
 * header row itself. Saved mappings match a file when its header, read with their settings, equals
 * theirs exactly.
 */
final class CsvReader
{
    public const ENCODINGS = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    public const DELIMITERS = [',', ';', "\t", '|'];

    private const MAX_PREAMBLE_LINES = 20;

    /**
     * Guess the encoding, delimiter and preamble length of a file.
     *
     * @return array{encoding: string, delimiter: string, skip_lines: int}
     */
    public static function detect(string $bytes): array
    {
        $encoding = mb_check_encoding($bytes, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        $lines = array_slice(self::lines(self::decode($bytes, $encoding)), 0, 60);
        $best = ['delimiter' => ',', 'skip_lines' => 0, 'columns' => 0];

        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(fn (string $line): int => $line === '' ? 0 : count(str_getcsv($line, $delimiter, '"', '')), $lines);

            for ($skip = 0; $skip <= min(self::MAX_PREAMBLE_LINES, count($lines) - 1); $skip++) {
                $columns = $counts[$skip];

                if ($columns < 2) {
                    continue;
                }

                $following = array_filter(array_slice($counts, $skip + 1), fn (int $count): bool => $count > 0);
                $consistent = array_filter($following, fn (int $count): bool => $count === $columns);

                // Every following row has the header's width (a footer line or two may differ).
                if (count($following) - count($consistent) <= min(2, intdiv(count($following), 10))) {
                    if ($columns > $best['columns']) {
                        $best = ['delimiter' => $delimiter, 'skip_lines' => $skip, 'columns' => $columns];
                    }

                    break;
                }
            }
        }

        return ['encoding' => $encoding, 'delimiter' => $best['delimiter'], 'skip_lines' => $best['skip_lines']];
    }

    /**
     * Decode to UTF-8 and drop a byte order mark.
     */
    public static function decode(string $bytes, string $encoding): string
    {
        $text = $encoding === 'UTF-8' ? $bytes : (string) mb_convert_encoding($bytes, 'UTF-8', $encoding);

        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }

    /**
     * Read the header and the data rows. Lines are 1-based physical line numbers in the file;
     * empty rows are dropped.
     *
     * @return array{header: list<string>, rows: list<array{line: int, cells: list<string>}>}
     */
    public static function read(string $bytes, string $encoding, string $delimiter, int $skipLines): array
    {
        $text = self::decode($bytes, $encoding);
        $offset = 0;

        for ($i = 0; $i < $skipLines; $i++) {
            $next = strpos($text, "\n", $offset);
            $offset = $next === false ? strlen($text) : $next + 1;
        }

        $stream = fopen('php://temp', 'r+');
        assert($stream !== false);
        fwrite($stream, substr($text, $offset));
        rewind($stream);

        $header = null;
        $rows = [];
        $line = $skipLines + 1;
        $position = 0;
        $body = substr($text, $offset);

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $start = $line;
            $end = (int) ftell($stream);
            $line += substr_count($body, "\n", $position, $end - $position);
            $position = $end;

            /** @var list<string> $cells */
            $cells = array_map(fn (?string $cell): string => (string) $cell, $cells);

            if ($header === null) {
                $header = array_map('trim', $cells);

                continue;
            }

            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = ['line' => $start, 'cells' => $cells];
        }

        fclose($stream);

        return ['header' => $header ?? [], 'rows' => $rows];
    }

    /**
     * Identify a shape: the same file read with the same settings has the same signature.
     *
     * @param  list<string>  $header
     */
    public static function signature(string $encoding, string $delimiter, int $skipLines, array $header): string
    {
        return hash('sha256', json_encode([$encoding, $delimiter, $skipLines, $header], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_map(fn (string $line): string => rtrim($line, "\r"), explode("\n", $text));
    }
}
