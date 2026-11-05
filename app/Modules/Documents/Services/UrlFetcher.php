<?php

namespace App\Modules\Documents\Services;

use App\Support\Settings;
use Closure;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads a document from an allowlisted HTTPS host. Blocks internal addresses (SSRF), pins the
 * checked IP for the request, follows redirects only to allowlisted hosts, and limits time and size.
 */
class UrlFetcher
{
    /** Overridable in tests: host => list of IPs. */
    public static ?Closure $resolver = null;

    /** @return string path of a temporary file (caller deletes it) */
    public function fetch(string $url, int $maxBytes): string
    {
        $redirects = 0;
        while (true) {
            [$host, $ip] = $this->check($url);
            $tmp = tempnam(sys_get_temp_dir(), 'doc');
            $port = parse_url($url, PHP_URL_PORT) ?? 443;
            $response = Http::withOptions([
                'allow_redirects' => false,
                'sink' => $tmp,
                'curl' => [CURLOPT_RESOLVE => ["$host:$port:$ip"]],
                'progress' => function ($total, $downloaded) use ($maxBytes) {
                    if ($downloaded > $maxBytes) {
                        throw new RuntimeException('too large');
                    }
                },
            ])->timeout(config('documents.url.timeout'))->withUserAgent('TrinixAdmission-DocumentFetcher/1.0')->get($url);

            if ($response->redirect()) {
                @unlink($tmp);
                if (++$redirects > config('documents.url.max_redirects')) {
                    throw new RuntimeException('Too many redirects.');
                }
                $url = (string) $response->header('Location');

                continue;
            }
            if (! $response->successful()) {
                @unlink($tmp);
                throw new RuntimeException("The link returned HTTP {$response->status()}.");
            }
            // The sink may not be used by fakes; make sure the body is on disk.
            if (! filesize($tmp)) {
                file_put_contents($tmp, $response->body());
            }
            if (filesize($tmp) > $maxBytes) {
                @unlink($tmp);
                throw new RuntimeException('The file is too large.');
            }

            return $tmp;
        }
    }

    /** @return array{0: string, 1: string} host and the public IP it resolved to */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Only plain https:// links are accepted.');
        }
        $host = strtolower($parts['host']);
        if (! $this->allowed($host)) {
            throw new RuntimeException("The host $host is not on the allowed list (Settings → Document URL hosts).");
        }
        $ips = self::$resolver ? (self::$resolver)($host) : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new RuntimeException("The host $host could not be found.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException("The host $host points to an internal address.");
            }
        }

        return [$host, $ips[0]];
    }

    private function allowed(string $host): bool
    {
        foreach ((array) Settings::get('document_url_hosts', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }

        return false;
    }
}
