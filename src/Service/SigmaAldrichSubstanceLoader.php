<?php

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LoggerInterface;
use App\Entity\Substance;
use App\Entity\Statement;
use App\Entity\Symbol;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Description of SigmaAldrichSubstanceLoader
 *
 * @author timbernhard
 */
class SigmaAldrichSubstanceLoader implements SubstanceLoaderInterface {

    private $em;
    private $statement_repo;
    private $signal_repo;
    private $substance_repo;
    private $logger;
    protected $COOKIE = "Cookie: SialLocaleDef=CountryCode~CH|WebLang~-3|; country=SWISC; Cck=present&dtPC=-; dtLatC=332";

    const TRIM_CHARACTERS = " \t\n\r\0\x0B\xc2\xa0";
    const URL = "https://www.sigmaaldrich.com";

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger) {
        $this->em = $em;
        $this->logger = $logger;
        $this->signal_repo = $em->getRepository(Symbol::class);
        $this->substance_repo = $em->getRepository(Substance::class);
        $this->statement_repo = $em->getRepository(Statement::class);
    }

    /**
     * @inheritDocs
     * @param string $search
     * @return type
     */
    public function loadSubstance(string $search) {
        $substance = $this->substance_repo->findByAny($search);
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
                    if (!$this->substance_repo->findOneByName($substance->getName()) && $substance) {
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
     * @param string $search
     * @return array
     */
    protected function loadSearchResults(string $search): array {
        $url = "https://www.sigmaaldrich.com/catalog/search?interface=All&N=0+&mode=partialmax&term=$search&lang=de&region=CH&focus=buildingblocks"; //&focus=buildingblocks or &focus=products
        $content = $this->curl($url);
        $resultCrawler = new Crawler($content);
        $results = $resultCrawler->filter('#searchBasedNavigation_widget .infoContainer .viewProducts');
        $this->logger->info('Results', array(
            'url' => $url,
            'results' => $results->count()
        ));
        $found = $this->reduceResults($results);
        return $found;
    }

    /**
     * Load all links of results of a sigma-aldrich search
     * 
     * @param string $search
     * @return array
     */
    protected function loadProductResults(string $search): array {
        $search = urlencode($search);
        $url = "https://www.sigmaaldrich.com/catalog/search?interface=All&term=$search&N=0&mode=mode+matchall&lang=de&region=CH&focus=product"; //&focus=buildingblocks or &focus=products
        $content = $this->curl($url);
        $resultCrawler = new Crawler($content);
        $results = $resultCrawler->filter('.productContainer .product-listing-outer .productNumberValue');
        $this->logger->info('Results', array(
            'url' => $url,
            'results' => $results->count()
        ));
        $found = $this->reduceResults($results);
        if (!count($found)) {
            return $this->loadSearchResults($search);
        }
        return $found;
    }

    /**
     * Reduce a Crawler to < 10 results
     * 
     * @param Crawler $results
     * @return array of links 
     */
    protected function reduceResults(Crawler $results) {
        $links = $results->filter('a');
        while ($links->count() > 5) {
            $links = $links->reduce(function (Crawler $node, $i) {
                // filters every second node
                // this is relatively arbitrary. could be done better
                return ($i % 2) == 0;
            });
        }
        $targets = $links->each(function (Crawler $node, $i) {
            return $node->attr('href');
        });
        return $targets;
    }

    /**
     * Construct a Substance from a sigma-aldrich uri
     * 
     * @param string $uri
     * @return Substance
     */
    protected function loadSubstanceFromUri(string $uri) {
        $result = $this->curl($uri . "?lang=de&region=CH");
        $resultCrawler = new Crawler($result);
        $substance = new Substance();
        $this->setSubstanceInfo($substance, $resultCrawler->filter('.productInfo'));
        $this->setSubstanceSds($substance, $resultCrawler->filter('.safetyBox'));
        $substance->setSource($uri);
        return $substance;
    }

    /**
     * Fetch all info of the Substance from the crawler and set it on the Substance
     * 
     * @param Substance $substance
     * @param Crawler $productInfo
     */
    protected function setSubstanceInfo(Substance &$substance, Crawler $productInfo) {
        if (!$productInfo->count()) {
            throw new \RuntimeException("Product Info is empty");
        }
        $dataCrawler = $productInfo->filter('ul.clearfix li p');
        $dataCrawler->each(function(Crawler $node, $i) use ($substance) {
            $test = strtolower(trim($node->text(), self::TRIM_CHARACTERS));
            switch (true) {
                case $this->stringStartsWith("cas number", $test):
                    $substance->setCASNumber($node->filter('a')->text());
                    break;
                case $this->stringStartsWith("pubchem", $test):
                    $substance->setPubchemId($node->filter('span')->text());
                    break;
                case $this->stringStartsWith("linear formula", $test):
                case $this->stringStartsWith("empirical formula", $test):
                    $substance->setFormula($node->filter('span')->text());
                    break;
                default:
                    $this->logger->info('Unused information: ' . $test);
            }
        });
        $h1 = $productInfo->filter('h1');
        if ($h1->count()) {
            $substance->setName($h1->text());
        } else {
            $this->logger->warning('No name found for Substance ' . $substance->getCASNumber());
        }
    }

    public static function stringStartsWith($start, $string) {
        // trimming not really necessary anymore
        $string = trim(strtolower($string), self::TRIM_CHARACTERS);
        $start = trim(strtolower($start), self::TRIM_CHARACTERS);
//        $this->logger->info("Checking '$start' against '$string'");
        return substr($string, 0, strlen($start)) === $start;
    }

    /**
     * Getch all SDS information for the substance, set it, from the crawler
     * 
     * @param Substance $substance
     * @param Crawler $safetyCrawler
     */
    protected function setSubstanceSds(Substance &$substance, Crawler $safetyCrawler) {
        if (!$safetyCrawler->count()) {
            throw new \RuntimeException("Safety Info is empty");
        }
        $symbols = explode(',', self::extractText($safetyCrawler, '.safetyRight#Symbol'));
        array_walk($symbols, function (&$symbol) {
            $symbol = $this->getSymbol(trim($symbol, self::TRIM_CHARACTERS));
        });
        $substance->setSymbols($symbols);
        $substance->setSignalWord(self::extractText($safetyCrawler, '.safetyRight span.warningLabel'));
        $substance->setRidadr(self::extractText($safetyCrawler, '.safetyRight#RIDADR'));
        $substance->setWgkGermany(self::extractText($safetyCrawler, '.safetyRight#WGK\ Germany'));
        $statements = self::extractText($safetyCrawler, '.safetyRight[id="Precautionary statements"]') . self::extractText($safetyCrawler, '.safetyRight[id="Hazard statements"]');
        if (!$statements || trim($statements, self::TRIM_CHARACTERS) == "") {
            $this->logger->warning("no statements found for " . $substance->getCASNumber());
        }
        $all_statements = preg_split('/(-|-|–|\r\n|\n|\r)+/', $statements);
        array_walk($all_statements, function (&$statement) {
            $statement = $this->getStatement(trim($statement, self::TRIM_CHARACTERS));
        });
        $substance->setStatements(array_filter($all_statements));
    }

    public static function extractText(Crawler $crawler, string $selector) {
        $filtered = $crawler->filter($selector);
        if ($filtered->count()) {
            return $filtered->text();
        }
        return NULL;
    }

    /**
     * Get the symbol for a specified identifier
     * 
     * @param string $search
     * @return Symbol
     */
    protected function getSymbol(string $search) {
        $symbol = $this->signal_repo->findOneBy(array('name' => $search));
        if (!$symbol && $search) {
            $symbol = new Symbol();
            $symbol->setName($search);
            $this->em->persist($symbol);
        }
        return $symbol;
    }

    /**
     * Get the statement for an identifier. If there is none yet, create a new Statement.
     * 
     * @param string $search
     * @return Statement
     */
    protected function getStatement(string $search) {
        $statement = $this->statement_repo->findOneBy(array('name' => $search));
        if (!$statement && $search) {
            $statement = new Statement();
            $statement->setName($search);
            switch (strtolower(substr($search, 0, 1))) {
                case 'p':
                    $statement->setType(Statement::TYPE_P);
                    break;
                case 'h':
                    $statement->setType(Statement::TYPE_H);
                    break;
                default:
                    $statement->setType(Statement::TYPE_UNKNOWN);
            }

            $this->em->persist($statement);
        }
        return $statement;
    }

    /**
     * Normalize URLs found in the HTML
     * 
     * @param string $uri
     * @return string
     * @throws \RuntimeException
     */
    protected function normalizeUri(string $uri): string {
        if (substr($uri, 0, strlen(self::URL)) == self::URL) {
            return $uri;
        } else if ($uri[0] == "/") {
            return self::URL . $uri;
        } else {
            throw new \RuntimeException("URL $uri could not be normalized");
        }
    }

    protected function curl($url) {
        $this->logger->info("Curling $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array($this->COOKIE));
        $return = curl_exec($ch);
        if (!$return || trim($return) == "" || (is_array($return) && !count($return))) {
//            throw new \RuntimeException(curl_error($ch), curl_errno($ch));
            $this->logger->alert("cURL failed", array(curl_error($ch), curl_errno($ch), $return, error_get_last()));
            $return = $this->getContents($url);
        }
        curl_close($ch);
//        $this->logger->info("website ($url) content:", array('content' => $return));
        return $return;
    }

    protected function getContents($url) {
        // Create a stream
        $opts = array(
            'http' => array(
                'method' => "GET",
                'header' => "Accept-language: en\r\n" .
                $this->COOKIE
            )
        );

        $context = stream_context_create($opts);

        // Open the file using the HTTP headers set above
        $file = file_get_contents($url, false, $context);
        return $file;
    }

}
