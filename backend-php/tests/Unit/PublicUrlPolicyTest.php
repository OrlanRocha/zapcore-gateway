<?php

declare(strict_types=1);

use App\Services\Network\PublicUrlException;
use App\Services\Network\PublicUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicUrlPolicyTest extends TestCase
{
    #[DataProvider('unsafeUrlProvider')]
    public function testRejectsUnsafeUrls(string $url): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => ['93.184.216.34']);

        $this->expectException(PublicUrlException::class);
        $policy->resolve($url);
    }

    public static function unsafeUrlProvider(): array
    {
        return [
            'userinfo' => ['https://user:pass@example.com/file.jpg'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/file.jpg'],
            'localhost' => ['http://localhost/file.jpg'],
            'localhost suffix' => ['http://api.localhost/file.jpg'],
            'ambiguous short IPv4' => ['http://127.1/file.jpg'],
            'integer IPv4' => ['http://2130706433/file.jpg'],
            'hex IPv4' => ['http://0x7f000001/file.jpg'],
            'octal IPv4' => ['http://0177.0.0.1/file.jpg'],
            'invalid hostname' => ['http://bad_host.example/file.jpg'],
            'loopback IPv4 literal' => ['http://127.0.0.1/file.jpg'],
            'unspecified IPv4 literal' => ['http://0.0.0.0/file.jpg'],
            'loopback IPv6 literal' => ['http://[::1]/file.jpg'],
            'ULA IPv6 literal' => ['http://[fc00::1]/file.jpg'],
            'link local IPv6 literal' => ['http://[fe80::1]/file.jpg'],
            'mapped IPv6 literal' => ['http://[::ffff:127.0.0.1]/file.jpg'],
        ];
    }

    #[DataProvider('blockedAddressProvider')]
    public function testRejectsEveryNonPublicDnsAddress(string $address): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => [$address]);

        $this->expectException(PublicUrlException::class);
        $policy->resolve('https://files.example.test/media.jpg');
    }

    public static function blockedAddressProvider(): array
    {
        return [
            ['127.0.0.1'], ['0.0.0.0'], ['10.0.0.1'], ['172.16.0.1'],
            ['192.168.1.1'], ['169.254.1.1'], ['169.254.169.254'],
            ['168.63.129.16'],
            ['224.0.0.1'], ['240.0.0.1'], ['192.0.2.1'], ['198.51.100.1'],
            ['203.0.113.1'], ['192.88.99.1'], ['255.255.255.255'], ['::1'], ['::'], ['fc00::1'],
            ['fe80::1'], ['ff02::1'], ['2001:db8::1'], ['::ffff:10.0.0.1'],
            ['2001:2::1'], ['2002::1'], ['3fff::1'],
        ];
    }

    public function testRejectsHostWhenAnyDnsAnswerIsUnsafe(): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => ['93.184.216.34', 'fd00::1']);

        $this->expectException(PublicUrlException::class);
        $policy->resolve('https://mixed.example.test/media.jpg');
    }

    public function testRejectsUnavailableDns(): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => []);

        $this->expectException(PublicUrlException::class);
        $policy->resolve('https://missing.example.test/media.jpg');
    }

    public function testAcceptsPublicHttpsAndReturnsAllPinnedAddresses(): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);

        $resolved = $policy->resolve('https://Files.Example.test:8443/a/b.jpg?x=1');

        self::assertSame('https', $resolved->scheme);
        self::assertSame('files.example.test', $resolved->host);
        self::assertSame(8443, $resolved->port);
        self::assertSame(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], $resolved->addresses);
        self::assertSame('files.example.test:8443:93.184.216.34', $resolved->resolveEntry('93.184.216.34'));
        self::assertSame('files.example.test:8443:[2606:2800:220:1:248:1893:25c8:1946]', $resolved->resolveEntry('2606:2800:220:1:248:1893:25c8:1946'));
    }

    public function testAcceptsCanonicalPublicIpLiteralWithoutDnsLookup(): void
    {
        $calls = 0;
        $policy = new PublicUrlPolicy(static function (string $host) use (&$calls): array {
            $calls++;
            return [];
        });

        $resolved = $policy->resolve('http://8.8.8.8/resource');

        self::assertSame(['8.8.8.8'], $resolved->addresses);
        self::assertSame(0, $calls);
    }
}
