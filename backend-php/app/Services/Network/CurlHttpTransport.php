<?php

namespace App\Services\Network;

final class CurlHttpTransport implements HttpTransport
{
    private const MAX_HEADER_BYTES = 65536;

    public function __construct(
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function send(
        ResolvedUrl $destination,
        string $method,
        array $headers,
        ?string $body,
        $target,
        int $maxBytes,
    ): HttpResponse {
        if (!is_resource($target) || $maxBytes < 0) {
            throw new \InvalidArgumentException('Invalid HTTP response target');
        }

        $responseHeaders = [];
        $headerBytes = 0;
        $bodyBytes = 0;
        $headerLimitExceeded = false;
        $bodyLimitExceeded = false;
        $address = $destination->addresses[0] ?? null;
        if (!is_string($address) || $address === '') {
            throw new PublicUrlException('The destination has no approved network address');
        }

        $ch = curl_init($destination->url);
        if ($ch === false) {
            throw new PublicUrlException('The destination request could not be initialized');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => [$destination->resolveEntry($address)],
            CURLOPT_USERAGENT => 'ZapCore-Gateway/1.2',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$headerBytes, &$headerLimitExceeded): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $headerLimitExceeded = true;
                    return 0;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($target, $maxBytes, &$bodyBytes, &$bodyLimitExceeded): int {
                $length = strlen($chunk);
                if ($bodyBytes + $length > $maxBytes) {
                    $bodyLimitExceeded = true;
                    return 0;
                }
                $written = fwrite($target, $chunk);
                if ($written === false || $written !== $length) {
                    return 0;
                }
                $bodyBytes += $written;
                return $written;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($headerLimitExceeded) {
            throw new PublicUrlException('The remote response headers are too large');
        }
        if ($bodyLimitExceeded) {
            throw new PublicUrlException('The remote response exceeds the allowed size');
        }
        if ($ok === false) {
            throw new PublicUrlException('The remote request failed');
        }

        return new HttpResponse($status, $responseHeaders, $bodyBytes);
    }
}
