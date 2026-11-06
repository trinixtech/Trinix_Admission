<?php

namespace App\Support\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A spreadsheet or CSV upload, checked by its extension and its first bytes rather than by the server's
 * file-type guess (shared hosts often report .xlsx as application/zip or octet-stream). An .xlsx (or .zip) must
 * start like a ZIP file; a .csv or .txt must be text.
 */
class UploadedTable implements ValidationRule
{
    /** @param list<string> $extensions */
    public function __construct(private array $extensions = ['csv', 'txt', 'xlsx']) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The file could not be uploaded. Try again.');

            return;
        }
        $ext = strtolower($value->getClientOriginalExtension());
        if (! in_array($ext, $this->extensions, true)) {
            $fail('Upload a '.implode(', ', array_map(fn ($e) => ".$e", $this->extensions)).' file (this one is '.($ext === '' ? 'without an extension' : ".$ext").').');

            return;
        }
        $head = (string) @file_get_contents($value->getRealPath(), false, null, 0, 4096);
        if (in_array($ext, ['xlsx', 'zip'], true)) {
            if (! str_starts_with($head, "PK\x03\x04") && ! str_starts_with($head, "PK\x05\x06")) {
                $fail($ext === 'xlsx' ? 'This file is not a real Excel (.xlsx) workbook. In Excel, use Save As → Excel Workbook (.xlsx), or CSV.' : 'This is not a ZIP file.');
            }

            return;
        }
        if (str_contains($head, "\0") || str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            $fail('This file is not plain text. Save it as CSV (comma separated) or as Excel (.xlsx).');
        }
    }
}
