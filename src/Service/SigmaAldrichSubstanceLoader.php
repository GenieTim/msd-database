<?php

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

/**
 * Description of SigmaAldrichSubstanceLoader
 *
 * @author timbernhard
 */
class SigmaAldrichSubstanceLoader implements SubstanceLoaderInterface {

    public const TRIM_CHARACTERS = " \t\n\r\0\x0B\xc2\xa0";
    public const URL = "https://www.sigmaaldrich.com";
    protected string $COOKIE = "Cookie: SialLocaleDef=CountryCode~CH|WebLang~-3|; country=SWISC; Cck=present&dtPC=-; dtLatC=332";

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubstanceRepository $substanceRepo,
        private readonly StatementRepository $statementRepo,
        private readonly SymbolRepository $symbolRepo,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $search): bool {
        return trim($search, self::TRIM_CHARACTERS) !== '';
    }

    /**
     * @inheritDocs
     */
    public function loadSubstance(string $search): ?Substance {
        $substance = $this->substanceRepo->findByAny($search);
        if (!$substance) {
            $returnSubstance = NULL;
            $possibleSubstances = $this->loadProductResults($search);
            if (count($possibleSubstances)) {
                foreach ($possibleSubstances as $attempt) {
                    $substance = $this->loadSubstanceFromUri($this->normalizeUri($attempt));
                    if (!$returnSubstance) {
                        $returnSubstance = $substance;
                    }
                    // check again for duplicates as the name could vary from the search
                    $name = $substance->getName();
                    if ($name !== null && !$this->substanceRepo->findOneByName($name)) {
                        $this->em->persist($substance);
                        $this->em->flush();
                    }
                }
                $substance = $returnSubstance;
            } else {
                $this->logger->warning('no results found by ' . self::class . ' in search for ' . $search);
            }
        }
        return $substance;
    }

    /**
     * Load all links of results of a sigma-aldrich search
     *
     * @return array<string>
     */
    protected function loadSearchResults(string $search): array {
        $url = "https://www.sigmaaldrich.com/catalog/search?interface=All&N=0+&mode=partialmax&term=$search&lang=de&region=CH&focus=buildingblocks";
        $content = $this->curl($url);
        if ($content === false || $content === '') {
            return [];
        }
        $resultCrawler = new Crawler($content);
        $results = $resultCrawler->filter('#searchBasedNavigation_widget .infoContainer .viewProducts');
        $this->logger->info('Results', [
            'url' => $url,
            'results' => $results->count(),
        ]);
        return $this->reduceResults($results);
    }

    /**
     * Load all links of results of a sigma-aldrich search
     *
     * @return array<string>
     */
    protected function loadProductResults(string $search): array {
        $searchEncoded = urlencode($search);
        $url = "https://www.sigmaaldrich.com/catalog/search?interface=All&term=$searchEncoded&N=0&mode=mode+matchall&lang=de&region=CH&focus=product";
        $content = $this->curl($url);
        if ($content === false || $content === '') {
            return [];
        }
        $resultCrawler = new Crawler($content);
        $results = $resultCrawler->filter('.productContainer .product-listing-outer .productNumberValue');
        $this->logger->info('Results', [
            'url' => $url,
            'results' => $results->count(),
        ]);
        $found = $this->reduceResults($results);
        if (!count($found)) {
            return $this->loadSearchResults($search);
        }
        return $found;
    }

    /**
     * Reduce a Crawler to <= 5 results
     *
     * @return array<string>
     */
    protected function reduceResults(Crawler $results): array {
        $links = $results->filter('a');
        while ($links->count() > 5) {
            $links = $links->reduce(fn(Crawler $node, $i): bool => $i % 2 === 0);
        }
        return $links->each(fn(Crawler $node, $i): string => (string) $node->attr('href'));
    }

    /**
     * Construct a Substance from a sigma-aldrich uri
     */
    protected function loadSubstanceFromUri(string $uri): \App\Entity\Substance {
        $result = $this->curl($uri . "?lang=de&region=CH");
        $resultCrawler = new Crawler($result === false ? '' : $result);
        $substance = new Substance();
        $this->setSubstanceInfo($substance, $resultCrawler->filter('.productInfo'));
        $this->setSubstanceSds($substance, $resultCrawler->filter('.safetyBox'));
        $substance->setSource($uri);
        return $substance;
    }

    /**
     * Fetch all info of the Substance from the crawler and set it on the Substance
     */
    protected function setSubstanceInfo(Substance &$substance, Crawler $productInfo): void {
        if (!$productInfo->count()) {
            throw new \RuntimeException("Product Info is empty");
        }
        $dataCrawler = $productInfo->filter('ul.clearfix li p');
        $dataCrawler->each(function(Crawler $node, $i) use ($substance): void {
            $test = strtolower(trim($node->text(), self::TRIM_CHARACTERS));
            match (true) {
                static::stringStartsWith("cas number", $test) => $substance->setCASNumber($node->filter('a')->text()),
                static::stringStartsWith("pubchem", $test) => $substance->setPubchemId((int) $node->filter('span')->text()),
                static::stringStartsWith("linear formula", $test), static::stringStartsWith("empirical formula", $test) => $substance->setFormula($node->filter('span')->text()),
                default => $this->logger->info('Unused information: ' . $test),
            };
        });
        $h1 = $productInfo->filter('h1');
        if ($h1->count()) {
            $substance->setName($h1->text());
        } else {
            $this->logger->warning('No name found for Substance ' . ($substance->getCASNumber() ?? 'unknown'));
        }
    }

    public static function stringStartsWith(string $start, string $string): bool {
        $string = trim(strtolower($string), self::TRIM_CHARACTERS);
        $start = trim(strtolower($start), self::TRIM_CHARACTERS);
        return str_starts_with($string, $start);
    }

    /**
     * Getch all SDS information for the substance, set it, from the crawler
     */
    protected function setSubstanceSds(Substance &$substance, Crawler $safetyCrawler): void {
        if (!$safetyCrawler->count()) {
            throw new \RuntimeException("Safety Info is empty");
        }
        $symbolsRaw = explode(',', (string) self::extractText($safetyCrawler, '.safetyRight#Symbol'));
        $symbols = [];
        foreach ($symbolsRaw as $symbolStr) {
            $sym = $this->getSymbol(trim($symbolStr, self::TRIM_CHARACTERS));
            if ($sym instanceof Symbol) {
                $symbols[] = $sym;
            }
        }
        $substance->setSymbols($symbols);
        $wgkText = self::extractText($safetyCrawler, '.safetyRight#WGK\ Germany');
        $substance->setWgkGermany($wgkText !== null && preg_match('/([1-3])/', $wgkText, $matches) ? (int) $matches[1] : null);
        $statements = (self::extractText($safetyCrawler, '.safetyRight[id="Precautionary statements"]')) . (self::extractText($safetyCrawler, '.safetyRight[id="Hazard statements"]'));
        if (trim($statements, self::TRIM_CHARACTERS) === "") {
            $this->logger->warning("no statements found for " . ($substance->getCASNumber() ?? 'unknown'));
        }
        $all_statements = preg_split('/(-|-|–|\r\n|\n|\r)+/', $statements);
        $statementsList = [];
        if (is_array($all_statements)) {
            foreach ($all_statements as $statementStr) {
                $st = $this->getStatement(trim($statementStr, self::TRIM_CHARACTERS));
                if ($st instanceof Statement) {
                    $statementsList[] = $st;
                }
            }
        }
        $substance->setStatements($statementsList);
    }

    public static function extractText(Crawler $crawler, string $selector): ?string {
        $filtered = $crawler->filter($selector);
        if ($filtered->count()) {
            return $filtered->text();
        }
        return NULL;
    }

    /**
     * Get the symbol for a specified identifier
     */
    protected function getSymbol(string $search): ?Symbol {
        $symbol = $this->symbolRepo->findOneBy(['name' => $search]);
        if (!$symbol && $search !== '') {
            $symbol = new Symbol();
            $symbol->setName($search);
            $this->em->persist($symbol);
        }
        return $symbol;
    }

    /**
     * Get the statement for an identifier. If there is none yet, create a new Statement.
     */
    protected function getStatement(string $search): ?Statement {
        $statement = $this->statementRepo->findOneBy(['name' => $search]);
        if (!$statement && $search !== '') {
            $statement = new Statement();
            $statement->setName($search);
            match (strtolower(substr($search, 0, 1))) {
                'p' => $statement->setType(Statement::TYPE_P),
                'h' => $statement->setType(Statement::TYPE_H),
                default => $statement->setType(Statement::TYPE_UNKNOWN),
            };

            $this->em->persist($statement);
        }
        return $statement;
    }

    /**
     * Normalize URLs found in the HTML
     *
     * @throws \RuntimeException
     */
    protected function normalizeUri(string $uri): string {
        if (str_starts_with($uri, self::URL)) {
            return $uri;
        }
        if (str_starts_with($uri, "/")) {
            return self::URL . $uri;
        }
        throw new \RuntimeException("URL $uri could not be normalized");
    }

    protected function curl(string $url): string|false {
        $this->logger->info("Curling $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [$this->COOKIE]);
        $return = curl_exec($ch);
        if ($return === false || trim((string) $return) === '') {
            $this->logger->alert("cURL failed", [curl_error($ch), curl_errno($ch), $return, error_get_last()]);
            $return = $this->getContents($url);
        }
        return $return;
    }

    protected function getContents(string $url): string|false {
        // Create a stream
        $opts = [
            'http' => [
                'method' => "GET",
                'timeout' => 4,
                'header' => "Accept-language: en\r\nUser-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36\r\n" .
                $this->COOKIE
            ]
        ];

        $context = stream_context_create($opts);

        // Open the file using the HTTP headers set above
        $file = @file_get_contents($url, false, $context);
        return $file;
    }

}
