<?php

namespace App\Modules\Imports\Services;

use Generator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Streams rows from a CSV or XLSX file (first sheet). The first non-empty row is the header row.
 * Memory use stays flat for large files.
 */
class FileReader
{
    /** @return Generator<int, array<string, mixed>> row number in the file => header => value */
    /** PHP extensions the reader needs for this kind of file, that this server does not have. */
    public static function missingExtensions(string $channel): array
    {
        $need = $channel === 'xlsx' ? ['zip', 'xmlreader', 'dom', 'libxml'] : [];

        return array_values(array_filter($need, fn ($e) => ! extension_loaded($e)));
    }

    /** A plain explanation for a file that could not be read. */
    public static function unreadable(string $channel): string
    {
        if ($missing = self::missingExtensions($channel)) {
            return 'This server cannot read Excel files yet: the PHP extension'.(count($missing) > 1 ? 's ' : ' ').implode(', ', $missing)
                .(count($missing) > 1 ? ' are' : ' is').' not enabled (cPanel → Select PHP Version → Extensions). Meanwhile, save the file as CSV UTF-8 and upload that.';
        }

        return $channel === 'xlsx'
            ? 'The Excel file could not be read. Open it in Excel, check it is not password-protected, and Save As "Excel Workbook (.xlsx)" or "CSV UTF-8", then upload again.'
            : 'The file could not be read. Save it as CSV UTF-8 (comma separated) with a header row, or as Excel .xlsx.';
    }

    public function rows(string $path, string $channel): Generator
    {
        $reader = $channel === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $row->toArray();
                    if ($headers === null) {
                        if ($row->isEmpty() || $this->blank($cells)) {
                            continue;
                        }
                        $headers = $this->headers($cells);

                        continue;
                    }
                    if ($this->blank($cells)) {
                        continue;
                    }
                    $assoc = [];
                    foreach ($headers as $i => $h) {
                        $assoc[$h] = $cells[$i] ?? null;
                    }
                    yield $line => $assoc;
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array{headers: list<string>, sample: list<array<string, mixed>>, rows: int} */
    public function inspect(string $path, string $channel, int $sample = 5): array
    {
        $rows = 0;
        $first = [];
        $headers = [];
        foreach ($this->rows($path, $channel) as $row) {
            $headers = $headers ?: array_keys($row);
            if ($rows < $sample) {
                $first[] = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $row);
            }
            $rows++;
        }
        if ($headers === []) {
            throw new RuntimeException('The file has no header row or no data rows.');
        }

        return ['headers' => $headers, 'sample' => $first, 'rows' => $rows];
    }

    /** @return list<string> unique, trimmed header names */
    private function headers(array $cells): array
    {
        $seen = [];
        $out = [];
        foreach ($cells as $i => $c) {
            $h = trim((string) $c) ?: 'Column '.($i + 1);
            $base = $h;
            $n = 2;
            while (isset($seen[$h])) {
                $h = "$base ($n)";
                $n++;
            }
            $seen[$h] = true;
            $out[] = $h;
        }

        return $out;
    }

    private function blank(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) ($c instanceof \DateTimeInterface ? 'x' : $c)) !== '') {
                return false;
            }
        }

        return true;
    }
}
