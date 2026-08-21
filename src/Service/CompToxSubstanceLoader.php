<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Entity\Substance;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CompToxSubstanceLoader loads chemical data from the EPA CompTox / CTX API.
 *
 * @author timbernhard
 */
class CompToxSubstanceLoader implements SubstanceLoaderInterface
{
    private const BASE_URL = 'https://api-ccte.epa.gov/chemical/search/equal';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $search): bool
    {
        return trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) !== '';
    }

    public function loadSubstance(string $search): ?Substance
    {
        $search = trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);
        if ($search === '') {
            return null;
        }

        try {
            $url = sprintf('%s/%s', self::BASE_URL, rawurlencode($search));
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 8,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                return null;
            }

            $data = $response->toArray();
            if ($data === []) {
                return null;
            }

            $item = $data[0] ?? $data;
            if (empty($item['preferredName']) && empty($item['casrn'])) {
                return null;
            }

            $substance = new Substance();
            $substance->setName((string) ($item['preferredName'] ?? $search));
            if (!empty($item['molFormula'])) {
                $substance->setFormula((string) $item['molFormula']);
            }
            if (!empty($item['casrn'])) {
                $substance->setCASNumber((string) $item['casrn']);
            }
            $dtxsid = $item['dtxsid'] ?? null;
            if ($dtxsid) {
                $substance->setSource(sprintf('https://comptox.epa.gov/dashboard/chemical/details/%s', $dtxsid));
            }

            return $substance;
        } catch (\Throwable $e) {
            $this->logger->info(sprintf('CompTox API search for "%s" skipped/failed: %s', $search, $e->getMessage()));
            return null;
        }
    }
}
