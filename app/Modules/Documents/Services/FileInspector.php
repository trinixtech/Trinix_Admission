<?php

namespace App\Modules\Documents\Services;

/** Identifies a file by its first bytes (the extension is never trusted). */
class FileInspector
{
    /** @return 'pdf'|'jpeg'|'png'|null */
    public function kind(string $path): ?string
    {
        $h = @fopen($path, 'rb');
        if (! $h) {
            return null;
        }
        $head = (string) fread($h, 8);
        fclose($h);

        return match (true) {
            str_starts_with($head, '%PDF-') => 'pdf',
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            default => null,
        };
    }

    /**
     * Problems with a file for a document type, or null when acceptable.
     *
     * @param  list<string>  $allowed
     */
    public function problem(string $path, array $allowed, int $maxKb): ?string
    {
        $size = @filesize($path);
        if (! $size) {
            return 'The file is empty.';
        }
        if ($size > $maxKb * 1024) {
            return 'The file is larger than '.round($maxKb / 1024, 1).' MB.';
        }
        $kind = $this->kind($path);
        if ($kind === null) {
            return 'The file is not a PDF, JPEG or PNG (checked by its content).';
        }
        if (! in_array($kind, $allowed, true)) {
            return strtoupper($kind).' is not accepted for this document (allowed: '.strtoupper(implode(', ', $allowed)).').';
        }

        return null;
    }
}
