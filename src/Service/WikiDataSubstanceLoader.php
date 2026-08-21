<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Entity\Statement;
use App\Entity\Substance;
use App\Entity\Symbol;
use App\Repository\StatementRepository;
use App\Repository\SubstanceRepository;
use App\Repository\SymbolRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WikiDataSubstanceLoader queries the Wikidata REST & Entity APIs
 * for chemical substance safety data.
 *
 * @author timbernhard
 */
class WikiDataSubstanceLoader implements SubstanceLoaderInterface
{
    private const WIKIDATA_API_BASE = 'https://www.wikidata.org/w/api.php';
    private const WIKIDATA_ENTITY_BASE = 'https://www.wikidata.org/wiki/Special:EntityData';
    private const LANGUAGE = 'en';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StatementRepository $statementRepo,
        private readonly SymbolRepository $symbolRepo,
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

        $results = $this->findSubstancesInApi($search);
        if ($results === []) {
            $this->logger->warning('no results found by ' . self::class . ' in search for ' . $search);
            return null;
        }

        foreach ($results as $result) {
            $entityId = $result['id'];
            if ($entityId !== '') {
                $substance = $this->translateApiIdToSubstance($entityId);
                if ($substance instanceof Substance) {
                    return $substance;
                }
            }
        }

        return null;
    }

    /**
     * Find substance results via Wikidata search API
     *
     * @return array<array{id: string, label: string, description?: string}>
     */
    public function findSubstancesInApi(string $search): array
    {
        try {
            $response = $this->httpClient->request('GET', self::WIKIDATA_API_BASE, [
                'query' => [
                    'action' => 'wbsearchentities',
                    'search' => $search,
                    'language' => self::LANGUAGE,
                    'format' => 'json',
                    'limit' => 10,
                ],
                'headers' => [
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            return $data['search'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->warning('Wikidata search error: ' . $e->getMessage(), ['exception' => $e]);
            return [];
        }
    }

    /**
     * Load the properties of a substance by Wikidata ID (e.g. Q153)
     */
    public function translateApiIdToSubstance(string $apiId): ?Substance
    {
        try {
            $url = sprintf('%s/%s.json', self::WIKIDATA_ENTITY_BASE, rawurlencode($apiId));
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            $entities = $data['entities'] ?? [];
            $entity = $entities[$apiId] ?? null;

            if (!$entity) {
                return null;
            }

            $claims = $entity['claims'] ?? [];
            $substance = new Substance();

            // Label / Name
            $label = $entity['labels'][self::LANGUAGE]['value'] ?? ($entity['labels']['de']['value'] ?? $apiId);
            $substance->setName((string) $label);
            $substance->setSource('https://www.wikidata.org/wiki/' . $apiId);

            // CAS: P231
            $cas = $this->extractClaimString($claims, 'P231');
            if ($cas) {
                $substance->setCASNumber($cas);
            }

            // PubChem CID: P662
            $pubchemCid = $this->extractClaimString($claims, 'P662');
            if ($pubchemCid && ctype_digit($pubchemCid)) {
                $substance->setPubchemId((int) $pubchemCid);
            }

            // Formula: P274
            $formula = $this->extractClaimString($claims, 'P274');
            if ($formula) {
                $substance->setFormula($formula);
            }

            // RTECS: P657
            $rtecs = $this->extractClaimString($claims, 'P657');
            if ($rtecs) {
                $substance->setRtecs($rtecs);
            }

            // Signal Word: P1033
            $signalWordId = $this->extractClaimEntityId($claims, 'P1033');
            if ($signalWordId) {
                $signalWord = match ($signalWordId) {
                    'Q11484089' => 'Danger',
                    'Q11484090' => 'Warning',
                    default => $signalWordId,
                };
                $substance->setSignalWord($signalWord);
            }

            // H-statements (P5041) & P-statements (P5042)
            $statementCodes = array_merge(
                $this->extractClaimStrings($claims, 'P5041'),
                $this->extractClaimStrings($claims, 'P5042')
            );
            if ($statementCodes !== []) {
                $matchingStatements = $this->statementRepo->getMatching($statementCodes);
                $substance->setStatements($matchingStatements);
            }

            // GHS Pictogram: P5040
            $symbols = [];
            $pictogramIds = $this->extractClaimEntityIds($claims, 'P5040');
            foreach ($pictogramIds as $picId) {
                // Map known Wikidata items or extract code
                $symbolName = $this->mapWikidataPictogram($picId);
                if ($symbolName) {
                    $symbol = $this->symbolRepo->findOneBy(['name' => $symbolName]);
                    if (!$symbol) {
                        $symbol = new Symbol();
                        $symbol->setName($symbolName);
                        $this->em->persist($symbol);
                    }
                    $symbols[] = $symbol;
                }
            }
            if ($symbols !== []) {
                $substance->setSymbols($symbols);
            }

            return $substance;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Error resolving Wikidata entity %s: %s', $apiId, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Extract main string value from claims
     *
     * @param array<string, mixed> $claims
     */
    private function extractClaimString(array $claims, string $property): ?string
    {
        $claimList = $claims[$property] ?? [];
        if (!empty($claimList[0]['mainsnak']['datavalue']['value'])) {
            $val = $claimList[0]['mainsnak']['datavalue']['value'];
            return is_string($val) ? $val : null;
        }
        return null;
    }

    /**
     * Extract multiple string values from claims
     *
     * @param array<string, mixed> $claims
     * @return array<string>
     */
    private function extractClaimStrings(array $claims, string $property): array
    {
        $values = [];
        $claimList = $claims[$property] ?? [];
        foreach ($claimList as $claim) {
            $val = $claim['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($val)) {
                $values[] = $val;
            }
        }
        return $values;
    }

    /**
     * Extract entity ID from claim datavalue
     *
     * @param array<string, mixed> $claims
     */
    private function extractClaimEntityId(array $claims, string $property): ?string
    {
        $claimList = $claims[$property] ?? [];
        return $claimList[0]['mainsnak']['datavalue']['value']['id'] ?? null;
    }

    /**
     * Extract all entity IDs from claim property
     *
     * @param array<string, mixed> $claims
     * @return array<string>
     */
    private function extractClaimEntityIds(array $claims, string $property): array
    {
        $ids = [];
        $claimList = $claims[$property] ?? [];
        foreach ($claimList as $claim) {
            $id = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function mapWikidataPictogram(string $qid): ?string
    {
        return match ($qid) {
            'Q50379568' => 'GHS01', // Exploding bomb
            'Q50379569' => 'GHS02', // Flame
            'Q50379570' => 'GHS03', // Flame over circle
            'Q50379571' => 'GHS04', // Gas cylinder
            'Q50379572' => 'GHS05', // Corrosion
            'Q50379573' => 'GHS06', // Skull and crossbones
            'Q50379574' => 'GHS07', // Exclamation mark
            'Q50379575' => 'GHS08', // Health hazard
            'Q50379576' => 'GHS09', // Environment
            default => null,
        };
    }
}

