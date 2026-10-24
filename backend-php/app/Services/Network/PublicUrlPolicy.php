<?php

namespace App\Services\Network;

final class PublicUrlPolicy
{
    private \Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = \Closure::fromCallable($resolver ?? self::resolveDns(...));
    }

    public function resolve(string $url): ResolvedUrl
    {
        if ($url === '' || trim($url) !== $url || strlen($url) > 2048) {
            throw new PublicUrlException('The destination URL is invalid');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new PublicUrlException('The destination URL is invalid');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new PublicUrlException('Only HTTP and HTTPS destinations are allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new PublicUrlException('Credentials are not allowed in destination URLs');
        }

        $rawHost = (string) ($parts['host'] ?? '');
        $host = strtolower(trim($rawHost, '[]'));
        $host = rtrim($host, '.');
        if (!$this->isValidHost($host)) {
            throw new PublicUrlException('The destination host is invalid');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new PublicUrlException('The destination port is invalid');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$this->canonicalAddress($host)];
        } else {
            $addresses = ($this->resolver)($host);
            if (!is_array($addresses) || $addresses === []) {
                throw new PublicUrlException('The destination host could not be resolved');
            }
            $addresses = array_values(array_unique(array_map(fn(mixed $ip): string => $this->canonicalAddress((string) $ip), $addresses)));
        }

        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                throw new PublicUrlException('The destination is blocked by the network security policy');
            }
        }

        return new ResolvedUrl(
            $this->canonicalUrl($parts, $scheme, $host, $port),
            $scheme,
            $host,
            $port,
            $addresses,
        );
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || str_contains($host, '%')) {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (preg_match('/^[0-9a-fx.]+$/i', $host)) {
            return false;
        }
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        return true;
    }

    private function canonicalAddress(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new PublicUrlException('The destination returned an invalid network address');
        }
        return (string) inet_ntop($packed);
    }

    private function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        $blockedCidrs = strlen($packed) === 4
            ? [
                '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '168.63.129.16/32', '169.254.0.0/16', '172.16.0.0/12',
                '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
                '224.0.0.0/4', '240.0.0.0/4',
            ]
            : [
                '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
                '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
                'fc00::/7', 'fe80::/10', 'ff00::/8',
            ];

        foreach ($blockedCidrs as $cidr) {
            if ($this->isInCidr($packed, $cidr)) {
                return false;
            }
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function isInCidr(string $packedAddress, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0 && substr($packedAddress, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($packedAddress[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
    }

    private function canonicalUrl(array $parts, string $scheme, string $host, int $port): string
    {
        $authorityHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority = $authorityHost . ($port !== $defaultPort ? ':' . $port : '');
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $scheme . '://' . $authority . $path . $query;
    }

    private static function resolveDns(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }
        return $addresses;
    }
}
