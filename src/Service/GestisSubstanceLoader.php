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
    private const string BASE_URL = 'https://gestis.dguv.de/data?name=';

    public function __construct(
        private readonly StatementRepository $statementRepo,
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
            $url = self::BASE_URL . rawurlencode($search);
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml',
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $html = $response->getContent(false);
            if (empty($html)) {
                return null;
            }

            $crawler = new Crawler($html, $url);
            return $this->parseGestisHtml($crawler, $url);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('GESTIS lookup for "%s" failed: %s', $search, $e->getMessage()), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function parseGestisHtml(Crawler $crawler, string $url): ?Substance
    {
        $h1 = $crawler->filter('h1.stoffname, h1');
        if ($h1->count() === 0) {
            return null;
        }

        $substance = new Substance();
        $substance->setName($h1->first()->text());
        $substance->setSource($url);

        // Formula
        $formulaNode = $crawler->filter('td span.acsf, .formula, #formula');
        if ($formulaNode->count() > 0) {
            $substance->setFormula($formulaNode->first()->text());
        }

        // CAS Number
        $casNode = $crawler->filterXPath('//td[contains(text(),"CAS")]/following-sibling::td | //span[contains(@class,"cas")]');
        if ($casNode->count() > 0) {
            $casText = trim($casNode->first()->text());
            if (preg_match('/\b\d{2,7}-\d{2}-\d\b/', $casText, $matches)) {
                $substance->setCASNumber($matches[0]);
            }
        }

        // Signal Word
        $signalWordNode = $crawler->filterXPath('//td[contains(.,"Signal Word")]/following-sibling::td | //td[contains(.,"Signalwort")]/following-sibling::td');
        if ($signalWordNode->count() > 0) {
            $substance->setSignalWord(trim($signalWordNode->first()->text()));
        }

        // WGK (Wassergefährdungsklasse)
        $wgkNode = $crawler->filterXPath('//td[contains(.,"WGK")] | //div[contains(.,"WGK")]');
        if ($wgkNode->count() > 0) {
            if (preg_match('/WGK\s*([1-3])/i', $wgkNode->first()->text(), $matches)) {
                $substance->setWgkGermany((int) $matches[1]);
            }
        }
        $statementText = $crawler->filter('.gefahr-hinweise, .hazard-statements, td')->each(fn(Crawler $node): string => $node->text());
        $allText = implode(' ', $statementText);
        $matching = $this->statementRepo->getMatching($allText);
        if ($matching !== []) {
            $substance->setStatements($matching);
        }

        return $substance;
    }
}

