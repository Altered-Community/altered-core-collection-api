<?php

namespace App\Tests\Unit\Client;

use App\Client\AlteredCoreClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AlteredCoreClientTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private CacheInterface $cache;
    private AlteredCoreClient $client;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache      = $this->createMock(CacheInterface::class);
        $this->client     = new AlteredCoreClient($this->httpClient, $this->cache, 'https://altered.example.com');
    }

    public function testGetCardsByReferencesReturnsEmptyArrayForEmptyInput(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $result = $this->client->getCardsByReferences([]);

        $this->assertSame([], $result);
    }

    public function testGetCardsByReferencesFetchesFromApiOnCacheMiss(): void
    {
        $ref      = 'ALT_CORE_B_AX_01_C';
        $cardData = ['reference' => $ref, 'name' => 'Yzmir Stargazer'];

        // Simulate cache miss: always call the provided callback and return its result
        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback): mixed {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturnSelf();
                return $callback($item);
            });
        $this->cache->method('delete')->willReturn(true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([$cardData]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://altered.example.com/api/cards/batch', $this->anything())
            ->willReturn($response);

        $result = $this->client->getCardsByReferences([$ref]);

        $this->assertArrayHasKey($ref, $result);
        $this->assertSame($cardData, $result[$ref]);
    }

    public function testGetCardsByReferencesReturnsCachedDataWithoutHttpCall(): void
    {
        $ref        = 'ALT_CORE_B_AX_01_C';
        $cachedCard = ['reference' => $ref, 'name' => 'Cached Card'];

        // Simulate cache hit: return data directly without calling the callback
        $this->cache->method('get')->willReturn($cachedCard);

        $this->httpClient->expects($this->never())->method('request');

        $result = $this->client->getCardsByReferences([$ref]);

        $this->assertSame($cachedCard, $result[$ref]);
    }

    public function testGetCardsByReferencesOnlyFetchesMissingReferences(): void
    {
        $cachedRef  = 'ALT_CORE_B_AX_01_C';
        $missingRef = 'ALT_CORE_B_OR_02_R';
        $cachedCard = ['reference' => $cachedRef, 'name' => 'Cached Card'];
        $fetchedCard = ['reference' => $missingRef, 'name' => 'Fetched Card'];

        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($cachedRef, $cachedCard): mixed {
                // Cache hit for cachedRef, miss for missingRef
                if (str_contains($key, md5($cachedRef . '_fr'))) {
                    return $cachedCard;
                }
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturnSelf();
                return $callback($item);
            });
        $this->cache->method('delete')->willReturn(true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([$fetchedCard]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) use ($missingRef) {
                return $options['json']['references'] === [$missingRef];
            }))
            ->willReturn($response);

        $result = $this->client->getCardsByReferences([$cachedRef, $missingRef]);

        $this->assertSame($cachedCard, $result[$cachedRef]);
        $this->assertSame($fetchedCard, $result[$missingRef]);
    }

    public function testGetBaseUrlReturnsConfiguredUrl(): void
    {
        $this->assertSame('https://altered.example.com', $this->client->getBaseUrl());
    }
}
