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
use App\Repository\SymbolRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * EchaSubstanceLoader loads legal and harmonised EU hazard classifications
 * (Annex VI of CLP Regulation) and REACH substance information from ECHA.
 *
 * @author timbernhard
 */
class EchaSubstanceLoader implements SubstanceLoaderInterface
{
    private const string ECHA_SEARCH_URL = 'https://echa.europa.eu/api/substances/search';
    private const string ECHA_DETAILS_URL = 'https://echa.europa.eu/api/substances';
    private const string CAS_REGEX = '/^\d{2,7}-\d{2}-\d$/';
    private const string EC_REGEX = '/^\d{3}-\d{3}-\d$/';

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

        try {
            // 1. Search for substance on ECHA
            $substanceSummary = $this->searchEchaSubstance($search);
            if ($substanceSummary === null) {
                $this->logger->info(sprintf('ECHA: No matching substance found for "%s"', $search));
                return null;
            }

            // 2. Fetch full details / classification if available and needed
            $hasClp = !empty($substanceSummary['clp'])
                || !empty($substanceSummary['harmonisedClassification'])
                || !empty($substanceSummary['classification']);

            $substanceId = $substanceSummary['id']
                ?? $substanceSummary['substanceId']
                ?? $substanceSummary['identifiers']['infocardId']
                ?? null;

            $details = null;
            if (!$hasClp && $substanceId !== null) {
                $details = $this->fetchEchaDetails((string) $substanceId);
            }

            // 3. Construct Substance entity
            return $this->buildSubstance($search, $substanceSummary, $details);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('ECHA loader failed for "%s": %s', $search, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Search ECHA API for substances by query term, CAS, or EC number.
     *
     * @return array<string, mixed>|null
     */
    private function searchEchaSubstance(string $query): ?array
    {
        try {
            $queryParams = ['q' => $query];
            if (preg_match(self::CAS_REGEX, $query)) {
                $queryParams['casNumber'] = $query;
            } elseif (preg_match(self::EC_REGEX, $query)) {
                $queryParams['ecNumber'] = $query;
            }

            $response = $this->httpClient->request('GET', self::ECHA_SEARCH_URL, [
                'query' => $queryParams,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                return null;
            }

            $data = $response->toArray();
            $results = $data['results'] ?? $data['data'] ?? $data['items'] ?? null;
            if ($results === null) {
                // The root payload itself might be a list or a single substance object
                $results = array_is_list($data) ? $data : [$data];
            }

            if (!is_array($results) || $results === []) {
                return null;
            }

            // Find best matching item
            $queryLower = strtolower($query);
            foreach ($results as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $cas = $item['cas_number'] ?? $item['casNumber'] ?? $item['identifiers']['casNumber'] ?? '';
                $ec = $item['ec_number'] ?? $item['ecNumber'] ?? $item['identifiers']['ecNumber'] ?? '';
                $name = $item['name'] ?? $item['substanceName'] ?? '';

                if (
                    strtolower((string) $cas) === $queryLower ||
                    strtolower((string) $ec) === $queryLower ||
                    strtolower((string) $name) === $queryLower
                ) {
                    return $item;
                }
            }

            return is_array($results[0]) ? $results[0] : null;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('ECHA search request failed for "%s": %s', $query, $e->getMessage()));
            return null;
        }
    }

    /**
     * Fetch detailed substance and C&L classification data from ECHA.
     *
     * @return array<string, mixed>|null
     */
    private function fetchEchaDetails(string $substanceId): ?array
    {
        try {
            $url = sprintf('%s/%s', self::ECHA_DETAILS_URL, rawurlencode($substanceId));
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('ECHA detail fetch failed for id "%s": %s', $substanceId, $e->getMessage()));
            return null;
        }
    }

    /**
     * Build Substance entity from ECHA search summary and details payload.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $details
     */
    private function buildSubstance(string $search, array $summary, ?array $details): Substance
    {
        $substance = new Substance();

        // 1. Name
        $name = $details['name']
            ?? $details['substanceName']
            ?? $summary['name']
            ?? $summary['substanceName']
            ?? null;

        if ($name === null || $name === '') {
            $name = $search;
        }
        $substance->setName((string) $name);

        // 2. Formula
        $formula = $details['formula']
            ?? $details['molecularFormula']
            ?? $details['identifiers']['formula']
            ?? $summary['formula']
            ?? $summary['molecularFormula']
            ?? $summary['identifiers']['formula']
            ?? null;

        if ($formula !== null && $formula !== '') {
            $substance->setFormula((string) $formula);
        }

        // 3. CAS Number
        $casNumber = $details['cas_number']
            ?? $details['casNumber']
            ?? $details['identifiers']['casNumber']
            ?? $summary['cas_number']
            ?? $summary['casNumber']
            ?? $summary['identifiers']['casNumber']
            ?? null;

        if ($casNumber !== null && preg_match(self::CAS_REGEX, (string) $casNumber)) {
            $substance->setCASNumber((string) $casNumber);
        } elseif (preg_match(self::CAS_REGEX, $search)) {
            $substance->setCASNumber($search);
        }

        // 4. Source URL
        $substanceId = $details['id']
            ?? $details['substanceId']
            ?? $details['identifiers']['infocardId']
            ?? $summary['id']
            ?? $summary['substanceId']
            ?? $summary['identifiers']['infocardId']
            ?? $details['ec_number']
            ?? $details['ecNumber']
            ?? $details['identifiers']['ecNumber']
            ?? $summary['ec_number']
            ?? $summary['ecNumber']
            ?? $summary['identifiers']['ecNumber']
            ?? null;

        if ($substanceId !== null) {
            $substance->setSource(sprintf('https://echa.europa.eu/substance-information/-/substanceinfo/%s', rawurlencode((string) $substanceId)));
        } else {
            $substance->setSource('https://echa.europa.eu/');
        }

        // 5. Harmonised CLP Classification Data
        $clpData = $details['clp']
            ?? $details['harmonisedClassification']
            ?? $details['classification']
            ?? $summary['clp']
            ?? $summary['harmonisedClassification']
            ?? $summary['classification']
            ?? [];

        // Signal Word
        $signalWord = $clpData['signal_word']
            ?? $clpData['signalWord']
            ?? $details['signal_word']
            ?? $details['signalWord']
            ?? $summary['signal_word']
            ?? $summary['signalWord']
            ?? null;

        if ($signalWord !== null && $signalWord !== '') {
            $substance->setSignalWord(ucfirst(strtolower((string) $signalWord)));
        }

        // GHS Symbols (GHS01 - GHS09)
        $rawSymbols = $clpData['pictograms']
            ?? $clpData['hazardPictograms']
            ?? $clpData['symbols']
            ?? $details['pictograms']
            ?? $details['symbols']
            ?? $summary['pictograms']
            ?? $summary['symbols']
            ?? [];

        $symbols = $this->parseSymbols($rawSymbols);
        if ($symbols !== []) {
            $substance->setSymbols($symbols);
        }

        // Hazard Statements (H-phrases) and Precautionary Statements (P-phrases)
        $hStatements = $clpData['hazard_statements']
            ?? $clpData['hazardStatements']
            ?? $details['hazard_statements']
            ?? $details['hazardStatements']
            ?? [];

        $pStatements = $clpData['precautionary_statements']
            ?? $clpData['precautionaryStatements']
            ?? $details['precautionary_statements']
            ?? $details['precautionaryStatements']
            ?? [];

        $statements = $this->parseStatements($hStatements, $pStatements);
        if ($statements !== []) {
            $substance->setStatements($statements);
        }

        return $substance;
    }

    /**
     * Parse and persist GHS Symbol entities.
     *
     * @return array<Symbol>
     */
    private function parseSymbols(mixed $rawSymbols): array
    {
        if (!is_array($rawSymbols)) {
            return [];
        }

        $symbolEntities = [];
        $symbolCodes = [];

        foreach ($rawSymbols as $symbol) {
            $symbolStr = is_array($symbol) ? ($symbol['code'] ?? $symbol['name'] ?? '') : (string) $symbol;
            if (preg_match_all('/GHS0[1-9]/i', $symbolStr, $matches)) {
                foreach ($matches[0] as $match) {
                    $symbolCodes[] = strtoupper($match);
                }
            }
        }

        $symbolCodes = array_values(array_unique($symbolCodes));
        foreach ($symbolCodes as $code) {
            $sym = $this->symbolRepo->findOneBy(['name' => $code]);
            if (!$sym) {
                $sym = new Symbol();
                $sym->setName($code);
                $this->em->persist($sym);
            }
            $symbolEntities[] = $sym;
        }

        return $symbolEntities;
    }

    /**
     * Parse and persist H and P Statement entities.
     *
     * @return array<Statement>
     */
    private function parseStatements(mixed $hStatements, mixed $pStatements): array
    {
        $statementEntities = [];
        $statementsMap = []; // code => description

        if (is_array($hStatements)) {
            foreach ($hStatements as $h) {
                if (is_array($h)) {
                    $code = trim((string) ($h['code'] ?? $h['name'] ?? ''));
                    $desc = !empty($h['description']) ? trim((string) $h['description']) : null;
                } else {
                    $hStr = trim((string) $h);
                    if (preg_match('/^([HEUH\d]+[a-zA-Z\+]*(?:\+[HEUH\d]+[a-zA-Z\+]*)*)(?::|\s+)(.*)$/i', $hStr, $m)) {
                        $code = strtoupper(trim($m[1]));
                        $desc = trim($m[2]);
                    } elseif (preg_match('/^([HEUH\d]+[a-zA-Z\+]*(?:\+[HEUH\d]+[a-zA-Z\+]*)*)/i', $hStr, $m)) {
                        $code = strtoupper(trim($m[1]));
                        $desc = null;
                    } else {
                        continue;
                    }
                }

                if ($code !== '' && !isset($statementsMap[$code])) {
                    $statementsMap[$code] = ['desc' => $desc, 'type' => Statement::TYPE_H];
                }
            }
        }

        if (is_array($pStatements)) {
            foreach ($pStatements as $p) {
                if (is_array($p)) {
                    $pCode = trim((string) ($p['code'] ?? $p['name'] ?? ''));
                    $pDesc = !empty($p['description']) ? trim((string) $p['description']) : null;
                    if ($pCode !== '' && !isset($statementsMap[$pCode])) {
                        $statementsMap[$pCode] = ['desc' => $pDesc, 'type' => Statement::TYPE_P];
                    }
                } else {
                    $pStr = trim((string) $p);
                    if (preg_match_all('/\bP\d{3}(?:\+P\d{3})*\b/i', $pStr, $pMatches)) {
                        foreach ($pMatches[0] as $match) {
                            $code = strtoupper($match);
                            if (!isset($statementsMap[$code])) {
                                $statementsMap[$code] = ['desc' => null, 'type' => Statement::TYPE_P];
                            }
                        }
                    }
                }
            }
        }

        foreach ($statementsMap as $code => $data) {
            $stmt = $this->statementRepo->findOneBy(['name' => $code]);
            if (!$stmt) {
                $stmt = new Statement();
                $stmt->setName($code);
                $stmt->setType($data['type']);
                if ($data['desc'] !== null) {
                    $stmt->setDescription($data['desc']);
                }
                $this->em->persist($stmt);
            } elseif ($data['desc'] !== null && ($stmt->getDescription() === null || $stmt->getDescription() === '')) {
                $stmt->setDescription($data['desc']);
            }
            $statementEntities[] = $stmt;
        }

        return $statementEntities;
    }
}
