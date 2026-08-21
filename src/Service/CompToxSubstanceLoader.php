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
 * CompToxSubstanceLoader loads chemical data from the EPA CompTox / CCTE Chemicals Dashboard API.
 *
 * @author timbernhard
 */
class CompToxSubstanceLoader implements SubstanceLoaderInterface
{
    private const string SEARCH_EQUAL_URL = 'https://api-ccte.epa.gov/chemical/search/equal';
    private const string SEARCH_START_WITH_URL = 'https://api-ccte.epa.gov/chemical/search/start-with';
    private const string SEARCH_CONTAIN_URL = 'https://api-ccte.epa.gov/chemical/search/contain';
    private const string BY_DTXSID_URL = 'https://api-ccte.epa.gov/chemical/detail/search/by-dtxsid';
    private const string BY_FORMULA_URL = 'https://api-ccte.epa.gov/chemical/msready/search/by-formula';
    private const string COMPTOX_DETAILS_URL = 'https://comptox.epa.gov/dashboard/chemical/details';

    private const string CAS_REGEX = '/^\d{2,7}-\d{2}-\d$/';
    private const string DTXSID_REGEX = '/^DTXSID\d+$/i';
    private const string FORMULA_REGEX = '/^[A-Z][a-z]?\d*([A-Z][a-z]?\d*)*$/';

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
            $data = $this->queryApi($search);
            if ($data === null) {
                return null;
            }

            $item = $this->extractChemicalRecord($data, $search);
            if ($item === null) {
                return null;
            }

            return $this->buildSubstance($item, $search);
        } catch (\Throwable $e) {
            $this->logger->info(sprintf('CompTox API search for "%s" skipped/failed: %s', $search, $e->getMessage()));
            return null;
        }
    }

    /**
     * Query EPA CCTE API with fallback strategies based on search input pattern.
     *
     * @return array<mixed>|null
     */
    private function queryApi(string $search): ?array
    {
        // 1. Search by DTXSID
        if (preg_match(self::DTXSID_REGEX, $search)) {
            $data = $this->requestJson(sprintf('%s/%s', self::SEARCH_EQUAL_URL, rawurlencode($search)));
            if ($data !== null) {
                return $data;
            }

            return $this->requestJson(sprintf('%s/%s', self::BY_DTXSID_URL, rawurlencode($search)));
        }

        // 2. Primary lookup via search/equal endpoint (exact match for CASRN, name, formula, etc.)
        $data = $this->requestJson(sprintf('%s/%s', self::SEARCH_EQUAL_URL, rawurlencode($search)));
        if ($data !== null) {
            return $data;
        }

        // 3. If search looks like a molecular formula, try the by-formula endpoint
        if (preg_match(self::FORMULA_REGEX, $search) && !preg_match('/^\d+$/', $search)) {
            $data = $this->requestJson(sprintf('%s/%s', self::BY_FORMULA_URL, rawurlencode($search)));
            if ($data !== null) {
                return $data;
            }
        }

        // 4. If search is not a CAS number, try prefix and substring search for chemical names
        if (!preg_match(self::CAS_REGEX, $search)) {
            $data = $this->requestJson(sprintf('%s/%s', self::SEARCH_START_WITH_URL, rawurlencode($search)));
            if ($data !== null) {
                return $data;
            }

            return $this->requestJson(sprintf('%s/%s', self::SEARCH_CONTAIN_URL, rawurlencode($search)));
        }

        return null;
    }

    /**
     * Perform HTTP GET request returning parsed JSON array.
     *
     * @return array<mixed>|null
     */
    private function requestJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 8,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 404) {
                return null;
            }

            if ($statusCode === 429) {
                $this->logger->warning(sprintf('CompTox API rate limit reached for URL: "%s"', $url));
                return null;
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning(sprintf('CompTox API returned HTTP %d for "%s"', $statusCode, $url));
                return null;
            }

            $data = $response->toArray(false);
            if ($data === []) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->info(sprintf('CompTox API request failed for "%s": %s', $url, $e->getMessage()));
            return null;
        }
    }

    /**
     * Extract the best matching chemical record from API response data.
     *
     * @param array<mixed> $data
     * @return array<string, mixed>|null
     */
    private function extractChemicalRecord(array $data, string $search): ?array
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        } elseif (isset($data['results']) && is_array($data['results'])) {
            $data = $data['results'];
        }

        if ($this->isAssociativeRecord($data)) {
            /** @var array<string, mixed> $data */
            return $this->hasChemicalFields($data) ? $data : null;
        }

        if ($data === []) {
            return null;
        }

        $validRecords = [];
        $lowerSearch = mb_strtolower($search);

        foreach ($data as $record) {
            if (!is_array($record) || !$this->hasChemicalFields($record)) {
                continue;
            }

            /** @var array<string, mixed> $record */
            $validRecords[] = $record;

            $prefName = $record['preferredName'] ?? $record['preferred_name'] ?? $record['substanceName'] ?? $record['name'] ?? null;
            if ($prefName !== null && mb_strtolower((string) $prefName) === $lowerSearch) {
                return $record;
            }

            $casrn = $record['casrn'] ?? $record['cas_rn'] ?? $record['casNumber'] ?? $record['cas_number'] ?? null;
            if ($casrn !== null && mb_strtolower((string) $casrn) === $lowerSearch) {
                return $record;
            }

            $dtxsid = $record['dtxsid'] ?? $record['dtx_sid'] ?? $record['dsstoxSubstanceId'] ?? null;
            if ($dtxsid !== null && mb_strtolower((string) $dtxsid) === $lowerSearch) {
                return $record;
            }

            $formula = $record['molFormula'] ?? $record['mol_formula'] ?? $record['formula'] ?? null;
            if ($formula !== null && mb_strtolower((string) $formula) === $lowerSearch) {
                return $record;
            }
        }

        return $validRecords[0] ?? null;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssociativeRecord(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return !array_is_list($arr);
    }

    /**
     * @param array<mixed> $record
     */
    private function hasChemicalFields(array $record): bool
    {
        return !empty($record['preferredName'])
            || !empty($record['preferred_name'])
            || !empty($record['substanceName'])
            || !empty($record['name'])
            || !empty($record['casrn'])
            || !empty($record['cas_rn'])
            || !empty($record['casNumber'])
            || !empty($record['cas_number'])
            || !empty($record['dtxsid'])
            || !empty($record['dtx_sid'])
            || !empty($record['dsstoxSubstanceId'])
            || !empty($record['molFormula'])
            || !empty($record['mol_formula'])
            || !empty($record['formula']);
    }

    /**
     * Build Substance entity from extracted chemical data.
     *
     * @param array<string, mixed> $item
     */
    private function buildSubstance(array $item, string $search): Substance
    {
        $substance = new Substance();

        $name = $item['preferredName'] ?? $item['preferred_name'] ?? $item['substanceName'] ?? $item['name'] ?? $item['title'] ?? null;
        $substance->setName($name !== null && trim((string) $name) !== '' ? (string) $name : $search);

        $formula = $item['molFormula'] ?? $item['mol_formula'] ?? $item['formula'] ?? $item['molecularFormula'] ?? $item['molecular_formula'] ?? null;
        if ($formula !== null && trim((string) $formula) !== '') {
            $substance->setFormula((string) $formula);
        }

        $casrn = $item['casrn'] ?? $item['cas_rn'] ?? $item['casNumber'] ?? $item['cas_number'] ?? $item['cas'] ?? null;
        if ($casrn !== null && trim((string) $casrn) !== '') {
            $substance->setCASNumber((string) $casrn);
        }

        $molWeight = $item['molWeight'] ?? $item['mol_weight'] ?? $item['molecularWeight'] ?? $item['molecular_weight'] ?? null;
        if ($molWeight !== null && is_numeric($molWeight)) {
            $substance->setMolecularWeight((float) $molWeight);
        }

        $smiles = $item['smiles'] ?? $item['canonicalSmiles'] ?? $item['canonical_smiles'] ?? null;
        if ($smiles !== null && trim((string) $smiles) !== '') {
            $substance->setSmiles((string) $smiles);
        }

        $inchi = $item['inchi'] ?? $item['inChI'] ?? null;
        if ($inchi !== null && trim((string) $inchi) !== '') {
            $substance->setInchi((string) $inchi);
        }

        $inchikey = $item['inchiKey'] ?? $item['inchikey'] ?? $item['inchi_key'] ?? null;
        if ($inchikey !== null && trim((string) $inchikey) !== '') {
            $substance->setInchikey((string) $inchikey);
        }

        $dtxsid = $item['dtxsid'] ?? $item['dtx_sid'] ?? $item['dsstoxSubstanceId'] ?? $item['dsstox_substance_id'] ?? null;
        if (empty($dtxsid) && preg_match(self::DTXSID_REGEX, $search)) {
            $dtxsid = $search;
        }

        if (!empty($dtxsid)) {
            $substance->setSource(sprintf('%s/%s', self::COMPTOX_DETAILS_URL, $dtxsid));
        }

        return $substance;
    }
}
