<?php

namespace App\Client;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AlteredCoreClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface      $cache,
        private readonly string              $alteredCoreUrl,
    ) {}

    public function getBaseUrl(): string
    {
        return $this->alteredCoreUrl;
    }

    /**
     * Fetch card data for a list of references from altered-core.
     * Results are cached per reference for 1 hour.
     *
     * @param  string[] $references
     * @param  string   $locale
     * @return array<string, array>  reference => card data
     */
    public function getCardsByReferences(array $references, string $locale = 'fr'): array
    {
        if (empty($references)) {
            return [];
        }

        $missing = [];
        $result  = [];

        foreach ($references as $ref) {
            $cacheKey = 'card_' . md5($ref . '_' . $locale);
            $cached   = $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(3600);
                return null;
            });

            if ($cached !== null) {
                $result[$ref] = $cached;
            } else {
                $missing[] = $ref;
            }
        }

        if (empty($missing)) {
            return $result;
        }

        $response = $this->httpClient->request('POST', $this->alteredCoreUrl . '/api/cards/batch', [
            'json'  => ['references' => $missing],
            'query' => ['locale' => $locale],
        ]);

        $cards = $response->toArray();

        foreach ($cards as $card) {
            $ref = $card['reference'] ?? null;
            if (!$ref) {
                continue;
            }

            $result[$ref] = $card;

            $cacheKey = 'card_' . md5($ref . '_' . $locale);
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($card) {
                $item->expiresAfter(3600);
                return $card;
            });
        }

        return $result;
    }
}
