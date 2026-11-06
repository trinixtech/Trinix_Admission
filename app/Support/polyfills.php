<?php

/*
 * Functions some shared hosts lack (no mbstring) and that the bundled symfony/polyfill-mbstring does not provide.
 * Loaded only when the real function is missing.
 */

if (! function_exists('mb_strcut')) {
    /** UTF-8 only: cuts by bytes, never in the middle of a character. */
    function mb_strcut(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $bytes = strlen($string);
        if ($start < 0) {
            $start = max(0, $bytes + $start);
        }
        if ($start >= $bytes) {
            return '';
        }
        while ($start > 0 && (ord($string[$start]) & 0xC0) === 0x80) {
            $start--;
        }
        $end = $length === null ? $bytes : ($length < 0 ? $bytes + $length : $start + $length);
        $end = min(max($end, $start), $bytes);
        while ($end < $bytes && $end > $start && (ord($string[$end]) & 0xC0) === 0x80) {
            $end--;
        }

        return substr($string, $start, $end - $start);
    }
}
