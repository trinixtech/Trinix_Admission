<?php

namespace App\Modules\Documents\Services;

/** Talks to clamd over TCP with the INSTREAM command. Files never leave our servers. */
class ClamAvScanner implements DocumentScanner
{
    public function name(): string
    {
        return 'ClamAV';
    }

    public function scan(string $path): array
    {
        $cfg = config('documents.clamav');
        $socket = @stream_socket_client("tcp://{$cfg['host']}:{$cfg['port']}", $errno, $errstr, 5);
        if (! $socket) {
            return ['status' => 'error', 'detail' => 'Scanner unreachable'];
        }
        stream_set_timeout($socket, $cfg['timeout']);
        try {
            fwrite($socket, "zINSTREAM\0");
            $file = fopen($path, 'rb');
            while (! feof($file)) {
                $chunk = fread($file, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fclose($file);
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\n ");
        } finally {
            fclose($socket);
        }

        if (str_ends_with($reply, 'OK')) {
            return ['status' => 'clean', 'detail' => 'ClamAV: no threat found'];
        }
        if (str_ends_with($reply, 'FOUND')) {
            return ['status' => 'infected', 'detail' => mb_substr('ClamAV: '.preg_replace('/^stream:\s*/', '', $reply), 0, 250)];
        }

        return ['status' => 'error', 'detail' => 'Scanner error'];
    }
}
