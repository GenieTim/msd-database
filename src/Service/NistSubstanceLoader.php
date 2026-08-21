<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Entity\Substance;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NistSubstanceLoader loads chemical data from the NIST Chemistry WebBook.
 *
 * @author timbernhard
 */
class NistSubstanceLoader implements SubstanceLoaderInterface
{
    public const string BASE_URL = 'https://webbook.nist.gov/cgi/cbook.cgi';
    private const string CAS_REGEX = '/^\d{2,7}-\d{2}-\d$/';
    private const string NIST_ID_REGEX = '/^[CB]\d+$/i';

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
            // Handle explicit prefix queries (e.g. ID=..., Name=..., Formula=...)
            if (preg_match('/^ID=(.+)$/i', $search, $matches)) {
                $id = trim($matches[1]);
                if (preg_match(self::CAS_REGEX, $id)) {
                    $url = sprintf('%s?ID=C%s&Units=SI', self::BASE_URL, str_replace('-', '', $id));
                } else {
                    $url = sprintf('%s?ID=%s&Units=SI', self::BASE_URL, rawurlencode($id));
                }
                return $this->queryAndParse($url, $id);
            }

            if (preg_match('/^Name=(.+)$/i', $search, $matches)) {
                $name = trim($matches[1]);
                $url = sprintf('%s?Name=%s&Units=SI', self::BASE_URL, rawurlencode($name));
                return $this->queryAndParse($url, $name);
            }

            if (preg_match('/^Formula=(.+)$/i', $search, $matches)) {
                $formula = trim($matches[1]);
                $url = sprintf('%s?Formula=%s&Units=SI', self::BASE_URL, rawurlencode($formula));
                return $this->queryAndParse($url, $formula);
            }

            // 1. CAS Number lookup
            if (preg_match(self::CAS_REGEX, $search)) {
                $cleanCas = str_replace('-', '', $search);
                $url = sprintf('%s?ID=C%s&Units=SI', self::BASE_URL, $cleanCas);
                $substance = $this->queryAndParse($url, $search);
                if ($substance instanceof Substance) {
                    return $substance;
                }

                $fallbackUrl = sprintf('%s?ID=%s&Units=SI', self::BASE_URL, rawurlencode($search));
                return $this->queryAndParse($fallbackUrl, $search);
            }

            // 2. Direct NIST ID (e.g. C64175, B1002065)
            if (preg_match(self::NIST_ID_REGEX, $search)) {
                $url = sprintf('%s?ID=%s&Units=SI', self::BASE_URL, rawurlencode($search));
                return $this->queryAndParse($url, $search);
            }

            // 3. Name search
            $nameUrl = sprintf('%s?Name=%s&Units=SI', self::BASE_URL, rawurlencode($search));
            $substance = $this->queryAndParse($nameUrl, $search);
            if ($substance instanceof Substance) {
                return $substance;
            }

            // 4. Formula search fallback
            $formulaUrl = sprintf('%s?Formula=%s&Units=SI', self::BASE_URL, rawurlencode($search));
            return $this->queryAndParse($formulaUrl, $search);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('NIST WebBook search for "%s" failed: %s', $search, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Perform HTTP GET request to NIST WebBook and parse the resulting HTML.
     */
    private function queryAndParse(string $url, string $search, int $depth = 0): ?Substance
    {
        if ($depth > 2) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->info(sprintf('NIST request to "%s" returned HTTP status %d', $url, $statusCode));
                return null;
            }

            $html = $response->getContent();
            if (trim($html) === '') {
                return null;
            }

            return $this->parseHtml($html, $url, $search, $depth);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('NIST WebBook query failed for "%s": %s', $url, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Parse NIST HTML into a Substance entity or resolve a search results page.
     */
    private function parseHtml(string $html, string $currentUrl, string $search, int $depth = 0): ?Substance
    {
        $crawler = new Crawler($html);

        // 1. Check for Not Found pages
        $title = '';
        $titleNodes = $crawler->filter('title');
        if ($titleNodes->count() > 0) {
            $title = trim($titleNodes->text());
        }

        if (str_contains(strtolower($title), 'not found')) {
            return null;
        }

        $h1Nodes = $crawler->filter('h1');
        $h1Text = '';
        if ($h1Nodes->count() > 0) {
            $h1Text = trim($h1Nodes->first()->text());
            if (str_contains(strtolower($h1Text), 'not found')) {
                return null;
            }
        }

        if (str_contains($html, 'was not found in the database') || str_contains($html, '0 matching species were found')) {
            return null;
        }

        // 2. Check for Search Results list page (disambiguation / multi-match)
        if (
            str_contains(strtolower($title), 'search results') ||
            str_contains(strtolower($h1Text), 'search results')
        ) {
            $links = $crawler->filter('main ol li a, ol li a, a[href*="cbook.cgi?ID="]');
            if ($links->count() === 0) {
                return null;
            }

            $bestHref = null;
            $searchLower = strtolower($search);

            // Exact link text match
            $links->each(function (Crawler $node) use ($searchLower, &$bestHref): void {
                if ($bestHref === null && strtolower(trim($node->text())) === $searchLower) {
                    $bestHref = $node->attr('href');
                }
            });

            // Prefix match
            if ($bestHref === null) {
                $links->each(function (Crawler $node) use ($searchLower, &$bestHref): void {
                    if ($bestHref === null && str_starts_with(strtolower(trim($node->text())), $searchLower)) {
                        $bestHref = $node->attr('href');
                    }
                });
            }

            // Fallback to first result link
            if ($bestHref === null) {
                $bestHref = $links->first()->attr('href');
            }

            if ($bestHref === null || $bestHref === '') {
                return null;
            }

            $targetUrl = str_starts_with($bestHref, 'http')
                ? $bestHref
                : 'https://webbook.nist.gov' . (str_starts_with($bestHref, '/') ? '' : '/') . $bestHref;

            return $this->queryAndParse($targetUrl, $search, $depth + 1);
        }

        // 3. Extract Substance Name
        $name = null;

        // Try JSON-LD schema metadata
        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$name): void {
            if ($name !== null) {
                return;
            }
            $decoded = json_decode($node->text(), true);
            if (is_array($decoded) && !empty($decoded['name']) && is_string($decoded['name'])) {
                $candidate = trim($decoded['name']);
                if (
                    $candidate !== '' &&
                    !str_contains(strtolower($candidate), 'not found') &&
                    !str_contains(strtolower($candidate), 'search results')
                ) {
                    $name = $candidate;
                }
            }
        });

        // Try H1 heading
        if ($name === null) {
            $h1Candidates = $crawler->filter('h1#Top, main h1, h1');
            if ($h1Candidates->count() > 0) {
                $candidate = trim($h1Candidates->first()->text());
                if (
                    $candidate !== '' &&
                    !str_contains(strtolower($candidate), 'not found') &&
                    !str_contains(strtolower($candidate), 'search results') &&
                    !str_contains(strtolower($candidate), 'chemistry webbook')
                ) {
                    $name = $candidate;
                }
            }
        }

        // Try Dublin Core / Open Graph metadata
        if ($name === null) {
            $meta = $crawler->filter('meta[name="DCTERMS.title"], meta[name="og:title"], meta[property="og:title"]');
            if ($meta->count() > 0) {
                $candidate = trim((string) $meta->first()->attr('content'));
                if (
                    $candidate !== '' &&
                    !str_contains(strtolower($candidate), 'not found') &&
                    !str_contains(strtolower($candidate), 'search results')
                ) {
                    $name = $candidate;
                }
            }
        }

        // Try HTML <title>
        if ($name === null && $title !== '') {
            $candidate = trim(preg_replace('/\s*-\s*NIST Chemistry WebBook.*$/i', '', $title) ?? $title);
            if ($candidate !== '') {
                $name = $candidate;
            }
        }

        // 4. Extract Chemical Formula
        $formula = null;

        // Try JSON-LD MolecularEntity
        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$formula): void {
            if ($formula !== null) {
                return;
            }
            $decoded = json_decode($node->text(), true);
            if (is_array($decoded) && !empty($decoded['molecularFormula']) && is_string($decoded['molecularFormula'])) {
                $formula = trim($decoded['molecularFormula']);
            }
        });

        // Try <li> Formula
        if ($formula === null) {
            $crawler->filter('main ul li, ul li')->each(function (Crawler $node) use (&$formula): void {
                if ($formula !== null) {
                    return;
                }
                $text = trim($node->text());
                if (preg_match('/^Formula\s*:\s*(.+)$/i', $text, $matches)) {
                    $cand = trim($matches[1]);
                    $cand = preg_replace('/\s+Molecular weight.*$/i', '', $cand) ?? $cand;
                    if ($cand !== '') {
                        $formula = trim($cand);
                    }
                }
            });
        }

        // Try regex for Formula in HTML
        if ($formula === null && preg_match('/<strong>(?:<a[^>]*>)?Formula(?:<\/a>)?:\s*<\/strong>\s*(.*?)(?:<\/li>|<br|<\/ul>)/is', $html, $matches)) {
            $cand = trim(strip_tags($matches[1]));
            if ($cand !== '') {
                $formula = $cand;
            }
        }

        // Try meta og:image:alt
        if ($formula === null) {
            $metaAlt = $crawler->filter('meta[name="og:image:alt"], meta[property="og:image:alt"]');
            if ($metaAlt->count() > 0) {
                $cand = trim((string) $metaAlt->first()->attr('content'));
                if ($cand !== '' && $cand !== 'site icon') {
                    $formula = $cand;
                }
            }
        }

        // 5. Extract CAS Registry Number
        $casNumber = null;

        // Try <li> CAS Registry Number
        $crawler->filter('main ul li, ul li')->each(function (Crawler $node) use (&$casNumber): void {
            if ($casNumber !== null) {
                return;
            }
            $text = trim($node->text());
            if (str_contains($text, 'CAS') && preg_match('/(\d{2,7}-\d{2}-\d)/', $text, $matches)) {
                $casNumber = $matches[1];
            }
        });

        // Try regex for CAS in HTML
        if ($casNumber === null && preg_match('/CAS\s*(?:Registry\s*)?Number:?\s*<\/strong>\s*(\d{2,7}-\d{2}-\d)/i', $html, $matches)) {
            $casNumber = $matches[1];
        }

        // Fallback to search term if it was a CAS number
        if ($casNumber === null && preg_match(self::CAS_REGEX, $search)) {
            $casNumber = $search;
        }

        // 6. Extract Molecular Weight
        $molecularWeight = null;
        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$molecularWeight): void {
            if ($molecularWeight !== null) {
                return;
            }
            $decoded = json_decode($node->text(), true);
            if (is_array($decoded) && !empty($decoded['molecularWeight'])) {
                if (preg_match('/([\d\.]+)/', (string) $decoded['molecularWeight'], $matches)) {
                    $molecularWeight = (float) $matches[1];
                }
            }
        });
        if ($molecularWeight === null) {
            $crawler->filter('main ul li, ul li')->each(function (Crawler $node) use (&$molecularWeight): void {
                if ($molecularWeight !== null) {
                    return;
                }
                $text = trim($node->text());
                if (preg_match('/Molecular\s*weight:\s*([\d\.]+)/i', $text, $matches)) {
                    $molecularWeight = (float) $matches[1];
                }
            });
        }
        if ($molecularWeight === null && preg_match('/Molecular\s*weight:\s*<\/strong>\s*([\d\.]+)/i', $html, $matches)) {
            $molecularWeight = (float) $matches[1];
        }

        // 7. Extract InChI, InChIKey, SMILES
        $inchi = null;
        $inchikey = null;
        $smiles = null;
        $crawler->filter('main ul li, ul li')->each(function (Crawler $node) use (&$inchi, &$inchikey, &$smiles): void {
            $text = trim($node->text());
            if ($inchi === null && preg_match('/InChI\s*:\s*(InChI=[^\s<]+)/i', $text, $matches)) {
                $inchi = trim($matches[1]);
            }
            if ($inchikey === null && preg_match('/InChIKey\s*:\s*([A-Z]{14}-[A-Z0-9\-]+)/i', $text, $matches)) {
                $inchikey = trim($matches[1]);
            }
            if ($smiles === null && preg_match('/SMILES\s*:\s*(.+)$/i', $text, $matches)) {
                $smiles = trim($matches[1]);
            }
        });
        if ($inchi === null && preg_match('/(?:IUPAC Standard )?InChI:?\s*<\/strong>\s*(?:<tt>|<code>)?(InChI=[^<\s]+)/i', $html, $matches)) {
            $inchi = trim($matches[1]);
        }
        if ($inchikey === null && (preg_match('/InChIKey=([A-Z]{14}-[A-Z]{10}-[A-Z0-9])/i', $html, $matches) || preg_match('/(?:IUPAC Standard )?InChIKey:?\s*<\/strong>\s*(?:<tt>|<code>)?([A-Z]{14}-[A-Z]{10}-[A-Z0-9])/i', $html, $matches))) {
            $inchikey = trim($matches[1]);
        }
        if ($smiles === null && preg_match('/SMILES\s*:\s*<\/strong>\s*(?:<code>|<tt>)?([^<]+)/i', $html, $matches)) {
            $smiles = trim($matches[1]);
        }

        // 8. Extract Boiling Point, Melting Point, Density
        $boilingPoint = null;
        $meltingPoint = null;
        $density = null;
        $crawler->filter('main ul li, ul li')->each(function (Crawler $node) use (&$boilingPoint, &$meltingPoint, &$density): void {
            $text = trim($node->text());
            if ($boilingPoint === null && preg_match('/(?:Normal\s+)?Boiling\s*point\s*:\s*(.+)$/i', $text, $matches)) {
                $boilingPoint = trim($matches[1]);
            }
            if ($meltingPoint === null && preg_match('/(?:Normal\s+)?Melting\s*point\s*:\s*(.+)$/i', $text, $matches)) {
                $meltingPoint = trim($matches[1]);
            }
            if ($density === null && preg_match('/Density(?:\s*\([^)]*\))?\s*:\s*(.+)$/i', $text, $matches)) {
                $density = trim($matches[1]);
            }
        });
        if ($boilingPoint === null && preg_match('/(?:Normal\s+)?Boiling\s*point\s*:\s*<\/strong>\s*([^<]+)/i', $html, $matches)) {
            $boilingPoint = trim(strip_tags($matches[1]));
        }
        if ($meltingPoint === null && preg_match('/(?:Normal\s+)?Melting\s*point\s*:\s*<\/strong>\s*([^<]+)/i', $html, $matches)) {
            $meltingPoint = trim(strip_tags($matches[1]));
        }
        if ($density === null && preg_match('/Density(?:\s*\([^)]*\))?\s*:\s*<\/strong>\s*([^<]+)/i', $html, $matches)) {
            $density = trim(strip_tags($matches[1]));
        }

        // 9. Extract Other Names / Synonyms
        $synonyms = [];
        $crawler->filter('main ul li, ul li')->each(function (Crawler $node) use (&$synonyms): void {
            $text = trim($node->text());
            if (preg_match('/^Other\s*names\s*:\s*(.+)$/is', $text, $matches)) {
                $names = preg_split('/[;\n\r]+/', $matches[1]);
                if (is_array($names)) {
                    foreach ($names as $nameItem) {
                        $trimmed = trim($nameItem);
                        if ($trimmed !== '') {
                            $synonyms[] = $trimmed;
                        }
                    }
                }
            }
        });

        // 10. Source URL
        $sourceUrl = $currentUrl;
        if (preg_match('/cgi\/cbook\.cgi\?ID=([CB]\d+)/i', $html, $matches)) {
            $sourceUrl = sprintf('%s?ID=%s&Units=SI', self::BASE_URL, $matches[1]);
        } elseif (preg_match('/ID=([^&]+)/i', $currentUrl, $matches)) {
            $sourceUrl = sprintf('%s?ID=%s&Units=SI', self::BASE_URL, $matches[1]);
        }

        // If nothing meaningful could be extracted
        if ($name === null && $formula === null && $casNumber === null) {
            return null;
        }

        $substance = new Substance();
        $substance->setName($name ?? $search);
        if ($formula !== null && $formula !== '') {
            $substance->setFormula($formula);
        }
        if ($casNumber !== null) {
            $substance->setCASNumber($casNumber);
        }
        if ($molecularWeight !== null) {
            $substance->setMolecularWeight($molecularWeight);
        }
        if ($smiles !== null) {
            $substance->setSmiles($smiles);
        }
        if ($inchi !== null) {
            $substance->setInchi($inchi);
        }
        if ($inchikey !== null) {
            $substance->setInchikey($inchikey);
        }
        if ($boilingPoint !== null) {
            $substance->setBoilingPoint($boilingPoint);
        }
        if ($meltingPoint !== null) {
            $substance->setMeltingPoint($meltingPoint);
        }
        if ($density !== null) {
            $substance->setDensity($density);
        }
        if ($synonyms !== []) {
            $substance->setSynonyms($synonyms);
        }
        $substance->setSource($sourceUrl);

        return $substance;
    }
}
