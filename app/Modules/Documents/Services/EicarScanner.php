<?php

namespace App\Modules\Documents\Services;

/**
 * DEVELOPMENT AND TESTS ONLY: flags the standard EICAR test string and nothing else.
 * System health shows a warning when this scanner is active.
 */
class EicarScanner implements DocumentScanner
{
    public static bool $down = false;

    public function name(): string
    {
        return 'EICAR test scanner (not for production)';
    }

    public function scan(string $path): array
    {
        if (self::$down) {
            return ['status' => 'error', 'detail' => 'Scanner unreachable'];
        }
        $content = (string) file_get_contents($path);

        return str_contains($content, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')
            ? ['status' => 'infected', 'detail' => 'EICAR test signature']
            : ['status' => 'clean', 'detail' => 'No test signature'];
    }
}
