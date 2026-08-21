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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GestisSubstanceLoader queries the GESTIS chemical substance database.
 *
 * @author timbernhard
 */
class GestisSubstanceLoader implements SubstanceLoaderInterface
{
    private const string API_BASE = 'https://gestis-api.dguv.de/api';
    private const string API_TOKEN = 'dddiiasjhduuvnnasdkkwUUSHhjaPPKMasd';

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
            // 1. Search for substance by term or CAS
            $searchUrl = sprintf('%s/search/%s', self::API_BASE, rawurlencode($search));
            $searchResp = $this->httpClient->request('GET', $searchUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . self::API_TOKEN,
                    'Accept' => 'application/json',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 8,
            ]);

            if ($searchResp->getStatusCode() !== 200) {
                return null;
            }

            /** @var array<int, array<string, mixed>> $searchResults */
            $searchResults = $searchResp->toArray();
            if ($searchResults === []) {
                return null;
            }

            // Find best matching result (exact CAS match, exact name, or closest name match)
            $matchedItem = null;
            $searchLower = strtolower($search);

            // 1. Check exact CAS match
            foreach ($searchResults as $item) {
                if (($item['cas_nr'] ?? '') === $search) {
                    $matchedItem = $item;
                    break;
                }
            }

            // 2. Check exact name match
            if ($matchedItem === null) {
                foreach ($searchResults as $item) {
                    if (strtolower((string) ($item['name'] ?? '')) === $searchLower) {
                        $matchedItem = $item;
                        break;
                    }
                }
            }

            // 3. Check prefix / contains name match
            if ($matchedItem === null) {
                foreach ($searchResults as $item) {
                    $nameLower = strtolower((string) ($item['name'] ?? ''));
                    if (str_starts_with($nameLower, $searchLower)) {
                        $matchedItem = $item;
                        break;
                    }
                }
            }

            if ($matchedItem === null) {
                return null;
            }

            $zvgNr = $matchedItem['zvg_nr'] ?? null;
            if (!$zvgNr) {
                return null;
            }

            // 2. Fetch full substance article
            $articleUrl = sprintf('%s/article/en/%s', self::API_BASE, rawurlencode((string) $zvgNr));
            $articleResp = $this->httpClient->request('GET', $articleUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . self::API_TOKEN,
                    'Accept' => 'application/json',
                    'User-Agent' => 'MsdDatabase/1.0 (https://genieblog.ch)',
                ],
                'timeout' => 8,
            ]);

            if ($articleResp->getStatusCode() !== 200) {
                return null;
            }

            /** @var array<string, mixed> $article */
            $article = $articleResp->toArray();

            $substance = new Substance();
            $substance->setName((string) ($matchedItem['name'] ?? $article['name'] ?? $search));
            if (!empty($matchedItem['cas_nr'])) {
                $substance->setCASNumber((string) $matchedItem['cas_nr']);
            }
            $substance->setSource(sprintf('https://gestis.dguv.de/data?name=%s', $zvgNr));

            // Extract all text content from chapters
            $allText = '';
            foreach ($article['hauptkapitel'] ?? [] as $hk) {
                $allText .= ' ' . ($hk['text'] ?? '') . ' ' . ($hk['tables'] ?? '');
                foreach ($hk['unterkapitel'] ?? [] as $uk) {
                    $allText .= ' ' . ($uk['text'] ?? '') . ' ' . ($uk['tables'] ?? '');
                }
            }

            // Extract Signal Word
            if (preg_match('/"(Danger|Warning)"/i', $allText, $sigMatch)) {
                $substance->setSignalWord(ucfirst(strtolower($sigMatch[1])));
            }

            // Extract WGK (German Water Hazard Class)
            if (preg_match('/WGK\s*([1-3])/i', $allText, $wgkMatch)) {
                $substance->setWgkGermany((int) $wgkMatch[1]);
            } elseif (preg_match('/nicht\s+wassergef/i', $allText) || preg_match('/non-hazardous\s+to\s+water/i', $allText)) {
                $substance->setWgkGermany(0);
            }

            // Extract GHS Pictograms (e.g. ghs02, ghs07)
            if (preg_match_all('/ghs0([1-9])/i', $allText, $symMatches)) {
                $symbolCodes = array_values(array_unique(array_map(strtoupper(...), $symMatches[0])));
                $symbolEntities = [];
                foreach ($symbolCodes as $code) {
                    $sym = $this->symbolRepo->findOneBy(['name' => $code]);
                    if (!$sym) {
                        $sym = new Symbol();
                        $sym->setName($code);
                        $this->em->persist($sym);
                    }
                    $symbolEntities[] = $sym;
                }
                $substance->setSymbols($symbolEntities);
            }

            // Extract H-Statements (e.g. H225, H319)
            $statementEntities = [];
            if (preg_match_all('/\b(H(2[0-9]{2}|3[0-9]{2}|4[0-9]{2})[A-Za-z\+]*(?:\+H[0-9]{3}[A-Za-z\+]*)*)/', $allText, $hMatches)) {
                $hCodes = array_values(array_unique($hMatches[1]));
                foreach ($hCodes as $hCode) {
                    $stmt = $this->statementRepo->findOneBy(['name' => $hCode]);
                    if (!$stmt) {
                        $stmt = new Statement();
                        $stmt->setName($hCode);
                        $stmt->setType(Statement::TYPE_H);
                        $this->em->persist($stmt);
                    }
                    $statementEntities[] = $stmt;
                }
            }

            // Extract P-Statements (e.g. P210, P305+P351+P338)
            if (preg_match_all('/\b(P([1-5][0-9]{2})(?:\+P[0-9]{3})*)/', $allText, $pMatches)) {
                $pCodes = array_values(array_unique($pMatches[1]));
                foreach ($pCodes as $pCode) {
                    $stmt = $this->statementRepo->findOneBy(['name' => $pCode]);
                    if (!$stmt) {
                        $stmt = new Statement();
                        $stmt->setName($pCode);
                        $stmt->setType(Statement::TYPE_P);
                        $this->em->persist($stmt);
                    }
                    $statementEntities[] = $stmt;
                }
            }

            if ($statementEntities !== []) {
                $substance->setStatements($statementEntities);
            }

            return $substance;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('GESTIS lookup for "%s" failed: %s', $search, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }
}

