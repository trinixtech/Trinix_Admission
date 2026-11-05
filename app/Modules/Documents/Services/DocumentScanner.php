<?php

namespace App\Modules\Documents\Services;

interface DocumentScanner
{
    /**
     * @return array{status: 'clean'|'infected'|'error', detail: string}
     *                                                                   'error' means the scanner could not be reached; the file stays pending.
     */
    public function scan(string $path): array;

    public function name(): string;
}
