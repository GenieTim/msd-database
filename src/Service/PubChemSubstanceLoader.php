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
 * PubChemSubstanceLoader loads chemical substance safety and identity data
 * using the PubChem PUG REST and PUG View APIs.
 *
 * @author timbernhard
 */
class PubChemSubstanceLoader implements SubstanceLoaderInterface
{
    private const PUG_REST_BASE = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug';
    private const PUG_VIEW_BASE = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug_view';
    private const CAS_REGEX = '/^\d{2,7}-\d{2}-\d$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubstanceRepository $substanceRepo,
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

    /**
     * {@inheritdoc}
     */
    public function loadSubstance(string $search): ?Substance
    {
        $search = trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);
        if ($search === '') {
            return null;
        }

        try {
            // 1. Resolve input to PubChem CID
            $cid = $this->resolveToCid($search);
            if ($cid === null) {
                $this->logger->warning(sprintf('No PubChem CID found for search term: "%s"', $search));
                return null;
            }

            // Check if already stored in database by CID
            $existingSubstance = $this->substanceRepo->findOneBy(['pubchem_id' => $cid]);
            if ($existingSubstance instanceof Substance) {
                return $existingSubstance;
            }

            // 2. Load chemical identity properties & synonyms
            $properties = $this->fetchCompoundProperties($cid);
            $synonyms = $this->fetchCompoundSynonyms($cid);

            // 3. Load GHS safety classification data via PUG View
            $ghsData = $this->fetchGhsClassification($cid);

            // 4. Construct Substance entity
            $substance = new Substance();
            $substance->setPubchemId($cid);
            $substance->setSource(sprintf('https://pubchem.ncbi.nlm.nih.gov/compound/%d', $cid));

            // Name
            $name = $properties['Title'] ?? $properties['IUPACName'] ?? $search;
            $substance->setName((string) $name);

            // Formula
            if (!empty($properties['MolecularFormula'])) {
                $substance->setFormula((string) $properties['MolecularFormula']);
            }

            // CAS Number
            $casNumber = $this->extractCasNumber($search, $synonyms);
            if ($casNumber !== null) {
                $substance->setCASNumber($casNumber);
            }

            // Signal Word
            if (!empty($ghsData['signal_word'])) {
                $substance->setSignalWord($ghsData['signal_word']);
            }

            // Symbols / Pictograms
            $symbolEntities = [];
            foreach ($ghsData['symbols'] as $symbolCode) {
                $symbolEntity = $this->getOrCreateSymbol($symbolCode);
                if ($symbolEntity instanceof Symbol) {
                    $symbolEntities[] = $symbolEntity;
                }
            }
            $substance->setSymbols($symbolEntities);

            // Statements (H & P phrases)
            $statementEntities = [];
            foreach ($ghsData['h_statements'] as $hCode => $hDesc) {
                $statementEntity = $this->getOrCreateStatement((string) $hCode, $hDesc, Statement::TYPE_H);
                if ($statementEntity instanceof Statement) {
                    $statementEntities[] = $statementEntity;
                }
            }

            foreach ($ghsData['p_statements'] as $pCode) {
                $statementEntity = $this->getOrCreateStatement((string) $pCode, null, Statement::TYPE_P);
                if ($statementEntity instanceof Statement) {
                    $statementEntities[] = $statementEntity;
                }
            }
            $substance->setStatements($statementEntities);

            return $substance;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Error loading substance from PubChem for "%s": %s', $search, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Resolve search string to PubChem CID.
     */
    private function resolveToCid(string $search): ?int
    {
        // Direct integer CID
        if (ctype_digit($search)) {
            return (int) $search;
        }

        // CAS Number format
        if (preg_match(self::CAS_REGEX, $search)) {
            $url = sprintf('%s/compound/xref/rn/%s/cids/JSON', self::PUG_REST_BASE, rawurlencode($search));
            $data = $this->requestJson($url);
            if (!empty($data['IdentifierList']['CID'][0])) {
                return (int) $data['IdentifierList']['CID'][0];
            }

            // Fallback to substance xref
            $substanceUrl = sprintf('%s/substance/xref/rn/%s/cids/JSON', self::PUG_REST_BASE, rawurlencode($search));
            $substanceData = $this->requestJson($substanceUrl);
            if (!empty($substanceData['IdentifierList']['CID'][0])) {
                return (int) $substanceData['IdentifierList']['CID'][0];
            }
        }

        // Search by compound name
        $nameUrl = sprintf('%s/compound/name/%s/cids/JSON', self::PUG_REST_BASE, rawurlencode($search));
        $nameData = $this->requestJson($nameUrl);
        if (!empty($nameData['IdentifierList']['CID'][0])) {
            return (int) $nameData['IdentifierList']['CID'][0];
        }

        return null;
    }

    /**
     * Fetch chemical identity properties.
     *
     * @return array<string, mixed>
     */
    private function fetchCompoundProperties(int $cid): array
    {
        $url = sprintf(
            '%s/compound/cid/%d/property/MolecularFormula,MolecularWeight,IUPACName,Title/JSON',
            self::PUG_REST_BASE,
            $cid
        );
        $data = $this->requestJson($url);

        return $data['PropertyTable']['Properties'][0] ?? [];
    }

    /**
     * Fetch synonyms for CAS extraction.
     *
     * @return array<string>
     */
    private function fetchCompoundSynonyms(int $cid): array
    {
        $url = sprintf('%s/compound/cid/%d/synonyms/JSON', self::PUG_REST_BASE, $cid);
        $data = $this->requestJson($url);

        return $data['InformationList']['Information'][0]['Synonym'] ?? [];
    }

    /**
     * Extract CAS number from input or synonyms list.
     *
     * @param array<string> $synonyms
     */
    private function extractCasNumber(string $search, array $synonyms): ?string
    {
        if (preg_match(self::CAS_REGEX, $search)) {
            return $search;
        }

        foreach ($synonyms as $synonym) {
            $trimmed = trim($synonym);
            if (preg_match(self::CAS_REGEX, $trimmed)) {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Fetch GHS safety and classification data via PUG View.
     *
     * @return array{signal_word: ?string, symbols: array<string>, h_statements: array<string, ?string>, p_statements: array<string>}
     */
    private function fetchGhsClassification(int $cid): array
    {
        $result = [
            'signal_word' => null,
            'symbols' => [],
            'h_statements' => [],
            'p_statements' => [],
        ];

        $url = sprintf('%s/data/compound/%d/JSON?heading=GHS+Classification', self::PUG_VIEW_BASE, $cid);
        $data = $this->requestJson($url);

        if (empty($data['Record']['Section'])) {
            return $result;
        }

        $ghsSections = $this->findSectionsByHeading($data['Record']['Section'], 'GHS Classification');
        if ($ghsSections === []) {
            return $result;
        }

        foreach ($ghsSections as $section) {
            if (empty($section['Information'])) {
                continue;
            }

            foreach ($section['Information'] as $info) {
                $name = $info['Name'] ?? '';
                $stringWithMarkup = $info['Value']['StringWithMarkup'] ?? [];

                switch ($name) {
                    case 'Signal':
                        if ($result['signal_word'] === null && !empty($stringWithMarkup[0]['String'])) {
                            $result['signal_word'] = trim((string) $stringWithMarkup[0]['String']);
                        }
                        break;

                    case 'Pictogram(s)':
                        foreach ($stringWithMarkup as $item) {
                            if (!empty($item['Markup'])) {
                                foreach ($item['Markup'] as $markup) {
                                    $target = ($markup['URL'] ?? '') . ' ' . ($markup['Extra'] ?? '');
                                    if (preg_match('/(GHS0[1-9])/i', $target, $matches)) {
                                        $result['symbols'][] = strtoupper($matches[1]);
                                    }
                                }
                            }
                        }
                        break;

                    case 'GHS Hazard Statements':
                        foreach ($stringWithMarkup as $item) {
                            $statementStr = (string) ($item['String'] ?? '');
                            if (preg_match('/^(H\d{3}[a-zA-Z\+]*(?:\+[H\d{3}[a-zA-Z\+]*)*)(?::|\s*\([^)]*\):?|\s+)(.*)$/i', $statementStr, $matches)) {
                                $code = strtoupper(trim($matches[1]));
                                $desc = trim($matches[2]);
                                $descClean = preg_replace('/\s*\[.*?\]\s*$/', '', $desc);
                                if (!isset($result['h_statements'][$code])) {
                                    $result['h_statements'][$code] = $descClean;
                                }
                            } elseif (preg_match('/^(H\d{3}[a-zA-Z\+]*(?:\+[H\d{3}[a-zA-Z\+]*)*)/i', $statementStr, $matches)) {
                                $code = strtoupper(trim($matches[1]));
                                if (!isset($result['h_statements'][$code])) {
                                    $result['h_statements'][$code] = null;
                                }
                            }
                        }
                        break;

                    case 'Precautionary Statement Codes':
                        foreach ($stringWithMarkup as $item) {
                            $codeString = (string) ($item['String'] ?? '');
                            if (preg_match_all('/\bP\d{3}(?:\+P\d{3})*\b/i', $codeString, $matches)) {
                                foreach ($matches[0] as $pCode) {
                                    $result['p_statements'][] = strtoupper(trim($pCode));
                                }
                            }
                        }
                        break;
                }
            }

            if (!empty($result['signal_word']) || !empty($result['h_statements'])) {
                break;
            }
        }

        $result['symbols'] = array_values(array_unique($result['symbols']));
        $result['p_statements'] = array_values(array_unique($result['p_statements']));

        return $result;
    }

    /**
     * Recursively find sections matching a specific TOCHeading.
     *
     * @param array<int, mixed> $sections
     * @return array<int, mixed>
     */
    private function findSectionsByHeading(array $sections, string $heading): array
    {
        $matched = [];
        foreach ($sections as $section) {
            if (($section['TOCHeading'] ?? '') === $heading) {
                $matched[] = $section;
            }
            if (!empty($section['Section']) && is_array($section['Section'])) {
                $matched = array_merge($matched, $this->findSectionsByHeading($section['Section'], $heading));
            }
        }

        return $matched;
    }

    /**
     * Find or create Symbol entity.
     */
    private function getOrCreateSymbol(string $name): ?Symbol
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $symbol = $this->symbolRepo->findOneBy(['name' => $name]);
        if (!$symbol) {
            $symbol = new Symbol();
            $symbol->setName($name);
            $this->em->persist($symbol);
        }

        return $symbol;
    }

    /**
     * Find or create Statement entity.
     */
    private function getOrCreateStatement(string $name, ?string $description, int $type): ?Statement
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $statement = $this->statementRepo->findOneBy(['name' => $name]);
        if (!$statement) {
            $statement = new Statement();
            $statement->setName($name);
            $statement->setType($type);
            if (!empty($description)) {
                $statement->setDescription($description);
            }
            $this->em->persist($statement);
        } elseif ($description !== null && ($statement->getDescription() === null || $statement->getDescription() === '')) {
            $statement->setDescription($description);
        }

        return $statement;
    }

    /**
     * Perform HTTP GET request returning parsed JSON.
     *
     * @return array<string, mixed>|null
     */
    private function requestJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 404) {
                return null;
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                return $response->toArray();
            }

            $this->logger->warning(sprintf('PubChem API returned HTTP %d for "%s"', $statusCode, $url));
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('PubChem communication failure for "%s": %s', $url, $e->getMessage()));
            return null;
        }
    }
}
