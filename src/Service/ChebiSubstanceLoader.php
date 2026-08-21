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
 * ChebiSubstanceLoader queries the EMBL-EBI OLS4 API for ChEBI (Chemical Entities of Biological Interest).
 *
 * @author timbernhard
 */
class ChebiSubstanceLoader implements SubstanceLoaderInterface
{
    private const string OLS4_SEARCH_API = 'https://www.ebi.ac.uk/ols4/api/search';
    private const string OLS4_TERMS_API = 'https://www.ebi.ac.uk/ols4/api/ontologies/chebi/terms';
    private const string CHEBI_OBO_PREFIX = 'http://purl.obolibrary.org/obo/CHEBI_';
    private const string CAS_REGEX = '/^\d{2,7}-\d{2}-\d$/';
    private const string CHEBI_ID_REGEX = '/^(?:CHEBI:?|CHEBI_)?(\d+)$/i';

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
            // 1. If direct ChEBI ID
            if (preg_match(self::CHEBI_ID_REGEX, $search, $matches) && !preg_match(self::CAS_REGEX, $search)) {
                $chebiNumber = $matches[1];
                $iri = self::CHEBI_OBO_PREFIX . $chebiNumber;
                $termData = $this->fetchTermByIri($iri);
                if ($termData !== null) {
                    return $this->buildSubstanceFromTerm($termData, $search);
                }
            }

            // 2. Search via OLS4 API
            $searchResult = $this->searchOls4($search);
            if ($searchResult === null || empty($searchResult['response']['docs'])) {
                $this->logger->info(sprintf('ChEBI: No results found for "%s"', $search));
                return null;
            }

            /** @var array<int, array<string, mixed>> $docs */
            $docs = $searchResult['response']['docs'];
            $bestDoc = $this->selectBestDoc($docs, $search);

            // If we have an IRI, fetch the complete term details
            $iri = $bestDoc['iri'] ?? null;
            if (is_string($iri) && $iri !== '') {
                $termData = $this->fetchTermByIri($iri);
                if ($termData !== null) {
                    return $this->buildSubstanceFromTerm($termData, $search);
                }
            }

            // Fallback to building from search doc if term detail fetch fails
            return $this->buildSubstanceFromSearchDoc($bestDoc, $search);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('ChEBI loader failed for "%s": %s', $search, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Search OLS4 for terms in the chebi ontology.
     *
     * @return array<string, mixed>|null
     */
    private function searchOls4(string $query): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::OLS4_SEARCH_API, [
                'query' => [
                    'ontology' => 'chebi',
                    'q' => $query,
                    'rows' => 5,
                ],
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
            $this->logger->warning(sprintf('ChEBI OLS4 search request failed for "%s": %s', $query, $e->getMessage()));
            return null;
        }
    }

    /**
     * Fetch term details from OLS4 using term IRI.
     *
     * @return array<string, mixed>|null
     */
    private function fetchTermByIri(string $iri): ?array
    {
        try {
            // OLS4 requires double-encoded IRI in URL path
            $encodedIri = rawurlencode(rawurlencode($iri));
            $url = sprintf('%s/%s', self::OLS4_TERMS_API, $encodedIri);

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
            $this->logger->warning(sprintf('ChEBI fetch term failed for IRI "%s": %s', $iri, $e->getMessage()));
            return null;
        }
    }

    /**
     * Select the best matching document from search results.
     *
     * @param array<int, array<string, mixed>> $docs
     * @return array<string, mixed>
     */
    private function selectBestDoc(array $docs, string $search): array
    {
        $searchLower = strtolower($search);

        // 1. Exact match on label
        foreach ($docs as $doc) {
            $label = strtolower((string) ($doc['label'] ?? ''));
            if ($label === $searchLower) {
                return $doc;
            }
        }

        // 2. Exact match on obo_id or short_form
        foreach ($docs as $doc) {
            $oboId = strtolower((string) ($doc['obo_id'] ?? ''));
            $shortForm = strtolower((string) ($doc['short_form'] ?? ''));
            if ($oboId === $searchLower || $shortForm === $searchLower) {
                return $doc;
            }
        }

        return $docs[0];
    }

    /**
     * Construct Substance entity from OLS4 term object.
     *
     * @param array<string, mixed> $term
     */
    private function buildSubstanceFromTerm(array $term, string $search): Substance
    {
        if (isset($term['_embedded']['terms'][0]) && is_array($term['_embedded']['terms'][0])) {
            /** @var array<string, mixed> $term */
            $term = $term['_embedded']['terms'][0];
        }

        $substance = new Substance();

        // Name / Label
        $label = $term['label'] ?? $search;
        $substance->setName((string) $label);

        // ChEBI ID & Source link
        $oboId = $term['obo_id'] ?? null;
        if ($oboId !== null && is_scalar($oboId)) {
            $substance->setSource(sprintf('https://www.ebi.ac.uk/chebi/searchId.do?chebiId=%s', rawurlencode((string) $oboId)));
        } elseif (!empty($term['iri']) && is_string($term['iri'])) {
            $substance->setSource($term['iri']);
        }

        // Formula
        $formula = $this->extractFormulaFromTerm($term);
        if ($formula !== null) {
            $substance->setFormula($formula);
        }

        // Molecular Weight
        $mass = $this->extractMassFromTerm($term);
        if ($mass !== null) {
            $substance->setMolecularWeight($mass);
        }

        // SMILES, InChI, InChIKey
        $smiles = $this->extractSmilesFromTerm($term);
        if ($smiles !== null) {
            $substance->setSmiles($smiles);
        }

        $inchi = $this->extractInchiFromTerm($term);
        if ($inchi !== null) {
            $substance->setInchi($inchi);
        }

        $inchikey = $this->extractInchiKeyFromTerm($term);
        if ($inchikey !== null) {
            $substance->setInchikey($inchikey);
        }

        // Synonyms
        $synonyms = $this->extractSynonymsFromTerm($term);
        if ($synonyms !== null && $synonyms !== []) {
            $substance->setSynonyms($synonyms);
        }

        // CAS Number extraction
        $casNumber = $this->extractCasFromTerm($term);
        if ($casNumber === null && preg_match(self::CAS_REGEX, $search)) {
            $casNumber = $search;
        }
        if ($casNumber !== null) {
            $substance->setCASNumber($casNumber);
        }

        return $substance;
    }

    /**
     * Construct Substance entity from search doc when full term detail is unavailable.
     *
     * @param array<string, mixed> $doc
     */
    private function buildSubstanceFromSearchDoc(array $doc, string $search): Substance
    {
        $substance = new Substance();

        $label = $doc['label'] ?? $search;
        $substance->setName((string) $label);

        $oboId = $doc['obo_id'] ?? null;
        if ($oboId !== null && is_scalar($oboId)) {
            $substance->setSource(sprintf('https://www.ebi.ac.uk/chebi/searchId.do?chebiId=%s', rawurlencode((string) $oboId)));
        }

        if (preg_match(self::CAS_REGEX, $search)) {
            $substance->setCASNumber($search);
        }

        return $substance;
    }

    /**
     * Extract chemical formula from term data or annotations.
     *
     * @param array<string, mixed> $term
     */
    private function extractFormulaFromTerm(array $term): ?string
    {
        $annotation = is_array($term['annotation'] ?? null) ? $term['annotation'] : [];

        $candidateSources = [
            $annotation['formula'] ?? null,
            $annotation['generalized_empirical_formula'] ?? null,
            $annotation['http://purl.obolibrary.org/obo/chebi/formula'] ?? null,
            $annotation['http://purl.obolibrary.org/obo/chebi/generalized_empirical_formula'] ?? null,
            $term['formula'] ?? null,
            $term['generalized_empirical_formula'] ?? null,
        ];

        foreach ($candidateSources as $candidate) {
            if (is_array($candidate) && !empty($candidate[0]) && is_string($candidate[0])) {
                $formula = trim($candidate[0]);
                if ($formula !== '') {
                    return $formula;
                }
            } elseif (is_string($candidate)) {
                $formula = trim($candidate);
                if ($formula !== '') {
                    return $formula;
                }
            }
        }

        return null;
    }

    /**
     * Extract CAS number from ChEBI term data or cross-reference annotations.
     *
     * @param array<string, mixed> $term
     */
    private function extractCasFromTerm(array $term): ?string
    {
        $annotation = is_array($term['annotation'] ?? null) ? $term['annotation'] : [];

        $xrefLists = [
            $annotation['database_cross_reference'] ?? [],
            $annotation['http://purl.obolibrary.org/obo/chebi/database_cross_reference'] ?? [],
            $term['database_cross_reference'] ?? [],
        ];

        foreach ($xrefLists as $xrefs) {
            if (!is_array($xrefs)) {
                continue;
            }
            foreach ($xrefs as $xref) {
                if (!is_string($xref)) {
                    continue;
                }
                $xrefStr = trim($xref);
                if (preg_match('/^cas:?\s*(\d{2,7}-\d{2}-\d)$/i', $xrefStr, $m)) {
                    return $m[1];
                }
                if (preg_match(self::CAS_REGEX, $xrefStr)) {
                    return $xrefStr;
                }
            }
        }

        $oboXrefs = $term['obo_xref'] ?? [];
        if (is_array($oboXrefs)) {
            foreach ($oboXrefs as $xref) {
                if (is_array($xref)) {
                    $database = strtolower(trim((string) ($xref['database'] ?? '')));
                    $id = trim((string) ($xref['id'] ?? ''));
                    if ($database === 'cas' && preg_match(self::CAS_REGEX, $id)) {
                        return $id;
                    }
                    if (preg_match(self::CAS_REGEX, $id)) {
                        return $id;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $term
     */
    private function extractMassFromTerm(array $term): ?float
    {
        $annotation = is_array($term['annotation'] ?? null) ? $term['annotation'] : [];

        $candidateSources = [
            $annotation['mass'] ?? null,
            $annotation['http://purl.obolibrary.org/obo/chebi/mass'] ?? null,
            $annotation['monoisotopicmass'] ?? null,
            $annotation['molecular_weight'] ?? null,
            $term['mass'] ?? null,
        ];

        foreach ($candidateSources as $candidate) {
            if (is_array($candidate) && !empty($candidate[0])) {
                $val = trim((string) $candidate[0]);
                if (is_numeric($val)) {
                    return (float) $val;
                }
            } elseif (is_numeric($candidate)) {
                return (float) $candidate;
            } elseif (is_string($candidate)) {
                $val = trim($candidate);
                if (is_numeric($val)) {
                    return (float) $val;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $term
     */
    private function extractSmilesFromTerm(array $term): ?string
    {
        $annotation = is_array($term['annotation'] ?? null) ? $term['annotation'] : [];

        $candidateSources = [
            $annotation['smiles'] ?? null,
            $annotation['http://purl.obolibrary.org/obo/chebi/smiles'] ?? null,
            $term['smiles'] ?? null,
        ];

        foreach ($candidateSources as $candidate) {
            if (is_array($candidate) && !empty($candidate[0]) && is_string($candidate[0])) {
                $smiles = trim($candidate[0]);
                if ($smiles !== '') {
                    return $smiles;
                }
            } elseif (is_string($candidate)) {
                $smiles = trim($candidate);
                if ($smiles !== '') {
                    return $smiles;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $term
     */
    private function extractInchiFromTerm(array $term): ?string
    {
        $annotation = is_array($term['annotation'] ?? null) ? $term['annotation'] : [];

        $candidateSources = [
            $annotation['inchi'] ?? null,
            $annotation['http://purl.obolibrary.org/obo/chebi/inchi'] ?? null,
            $term['inchi'] ?? null,
        ];

        foreach ($candidateSources as $candidate) {
            if (is_array($candidate) && !empty($candidate[0]) && is_string($candidate[0])) {
                $inchi = trim($candidate[0]);
                if ($inchi !== '') {
                    return $inchi;
                }
            } elseif (is_string($candidate)) {
                $inchi = trim($candidate);
                if ($inchi !== '') {
                    return $inchi;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $term
     */
    private function extractInchiKeyFromTerm(array $term): ?string
    {
        $annotation = is_array($term['annotation'] ?? null) ? $term['annotation'] : [];

        $candidateSources = [
            $annotation['inchikey'] ?? null,
            $annotation['http://purl.obolibrary.org/obo/chebi/inchikey'] ?? null,
            $term['inchikey'] ?? null,
        ];

        foreach ($candidateSources as $candidate) {
            if (is_array($candidate) && !empty($candidate[0]) && is_string($candidate[0])) {
                $inchikey = trim($candidate[0]);
                if ($inchikey !== '') {
                    return $inchikey;
                }
            } elseif (is_string($candidate)) {
                $inchikey = trim($candidate);
                if ($inchikey !== '') {
                    return $inchikey;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $term
     * @return array<string>|null
     */
    private function extractSynonymsFromTerm(array $term): ?array
    {
        $annotation = is_array($term['annotation'] ?? null) ? $term['annotation'] : [];
        $synonyms = [];

        $sources = [
            $term['synonyms'] ?? [],
            $term['exact_synonyms'] ?? [],
            $term['related_synonyms'] ?? [],
            $annotation['synonym'] ?? [],
            $annotation['http://purl.obolibrary.org/obo/chebi/synonym'] ?? [],
            $annotation['has_exact_synonym'] ?? [],
            $annotation['has_related_synonym'] ?? [],
        ];

        foreach ($sources as $source) {
            if (is_array($source)) {
                foreach ($source as $item) {
                    if (is_string($item)) {
                        $trimmed = trim($item);
                        if ($trimmed !== '') {
                            $synonyms[] = $trimmed;
                        }
                    } elseif (is_array($item) && !empty($item['name']) && is_string($item['name'])) {
                        $trimmed = trim($item['name']);
                        if ($trimmed !== '') {
                            $synonyms[] = $trimmed;
                        }
                    }
                }
            } elseif (is_string($source)) {
                $trimmed = trim($source);
                if ($trimmed !== '') {
                    $synonyms[] = $trimmed;
                }
            }
        }

        $oboSynonyms = $term['obo_synonym'] ?? [];
        if (is_array($oboSynonyms)) {
            foreach ($oboSynonyms as $item) {
                if (is_array($item) && !empty($item['name']) && is_string($item['name'])) {
                    $trimmed = trim($item['name']);
                    if ($trimmed !== '') {
                        $synonyms[] = $trimmed;
                    }
                } elseif (is_string($item)) {
                    $trimmed = trim($item);
                    if ($trimmed !== '') {
                        $synonyms[] = $trimmed;
                    }
                }
            }
        }

        return $synonyms !== [] ? array_values(array_unique($synonyms)) : null;
    }
}
