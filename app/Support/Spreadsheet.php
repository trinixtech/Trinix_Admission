<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Excel downloads for reports and lists: a bold header row, then the rows; never cached by the browser. */
final class Spreadsheet
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $name, array $headers, iterable $rows): BinaryFileResponse
    {
        return self::sheets($name, [['title' => 'Sheet1', 'columns' => $headers, 'rows' => $rows]]);
    }

    /**
     * One sheet per table.
     *
     * @param  list<array{title: string, columns: list<string>, rows: iterable<array<int, mixed>>}>  $tables
     */
    public static function sheets(string $name, array $tables): BinaryFileResponse
    {
        if (! Compat::writeXlsx() || config('exports.format') === 'csv') {
            return self::csv($name, $tables);
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $w = new Writer;
        $w->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        foreach (array_values($tables) as $i => $t) {
            $sheet = $i === 0 ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]+/', ' ', $t['title']), 0, 31) ?: 'Sheet'.($i + 1));
            $w->addRow(Row::fromValuesWithStyle($t['columns'], $bold));
            foreach ($t['rows'] as $r) {
                $w->addRow(Row::fromValues(array_map(fn ($v) => is_bool($v) ? ($v ? 'Yes' : 'No') : $v, array_values($r))));
            }
        }
        $w->close();
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name).'.xlsx';

        return response()->download($path, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])
            ->deleteFileAfterSend();
    }

    /**
     * CSV instead of Excel when the server has no zip extension: one file, each table after its title and a blank
     * line, with a byte-order mark so Excel opens the Indian names and symbols correctly.
     */
    private static function csv(string $name, array $tables): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $fh = fopen($path, 'wb');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach (array_values($tables) as $i => $t) {
            if (count($tables) > 1) {
                if ($i > 0) {
                    fwrite($fh, "\r\n");
                }
                fputcsv($fh, [$t['title']], ',', '"', '');
            }
            fputcsv($fh, $t['columns'], ',', '"', '');
            foreach ($t['rows'] as $r) {
                fputcsv($fh, array_map(fn ($v) => is_bool($v) ? ($v ? 'Yes' : 'No') : self::safe($v), array_values($r)), ',', '"', '');
            }
        }
        fclose($fh);
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name).'.csv';

        return response()->download($path, $file, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    /** Text that a spreadsheet would run as a formula (=, +, -, @) is prefixed with an apostrophe. */
    private static function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && ! is_numeric($v) ? "'".$v : $v;
    }
}
