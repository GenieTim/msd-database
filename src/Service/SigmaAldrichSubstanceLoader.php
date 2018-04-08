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
    private $logger;

    const URL = "https://www.sigmaaldrich.com";

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger) {
        $this->em = $em;
        $this->logger = $logger;
        $this->signal_repo = $em->getRepository(Symbol::class);
        $this->statement_repo = $em->getRepository(Statement::class);
    }

    /**
     * @inheritDocs
     * @param string $search
     * @return type
     */
    public function loadSubstance(string $search) {
        $substance = $this->em->getRepository(Substance::class)->findByAny($search);
        if (!$substance) {
            $possibleSubstances = $this->loadSearchResults($search);
            // TODO: check others than just first
            if (count($possibleSubstances)) {
                $substance = $this->loadSubstanceFromUri($this->normalizeUri($possibleSubstances[0]));
                $this->em->persist($substance);
                $this->em->flush();
            } else {
                $this->logger->warning('no results found in search for ' . $search);
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
        $url = "https://www.sigmaaldrich.com/catalog/search?interface=ALL&N=0+&mode=partialmax&term=$search&lang=de&region=CH&focus=buildingblocks"; //&focus=buildingblocks or &focus=products
        $content = $this->curl($url);
        $resultCrawler = new Crawler($content);
        $results = $resultCrawler->filter('#searchBasedNavigation_widget .infoContainer .viewProducts a');
        $this->logger->info('Results', array(
            'url' => $url,
            'results' => $results->count()
        ));
        $found = $results->each(function (Crawler $node, $i) {
            $this->logger->info('Crawler node ' . $i);
            return $node->attr('href');
        });
        return $found;
    }

    /**
     * Construct a Substance from a sigma-aldrich uri
     * 
     * @param string $uri
     * @return Substance
     */
    protected function loadSubstanceFromUri(string $uri): Substance {
        $resultCrawler = new Crawler($this->curl($uri));
        $substance = new Substance();
        var_dump($resultCrawler->html());
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
        // TODO: do not hardcode info position
        var_dump($productInfo->html());
        $dataCrawler = $productInfo->filter('ul.clearfix li p span');
        var_dump($dataCrawler->html());
        $substance->setCASNumber($dataCrawler->filter('a')->first()->text());
        $substance->setFormula($dataCrawler->slice(1, 1)->text());
        $substance->setPubchemId($dataCrawler->filter('a.External')->text());
        $h1 = $productInfo->filter('h1');
        if ($h1->count()) {
            $substance->setName($h1->text());
        } else {
            $this->logger->warning('set name to formula of Substance ' . $substance->getCASNumber());
            $substance->setName($substance->getFormula());
        }
    }

    /**
     * Getch all SDS information for the substance, set it, from the crawler
     * 
     * @param Substance $substance
     * @param Crawler $safetyCrawler
     */
    protected function setSubstanceSds(Substance &$substance, Crawler $safetyCrawler) {
        $symbols = explode(',', $safetyCrawler->filter('.safetyRight#Symbol')->text());
        array_walk($symbols, function (&$symbol) {
            $symbol = $this->getSymbol(trim($symbol));
        });
        $substance->setSymbols($symbols);
        $substance->setSignalWord($safetyCrawler->filter('.safetyRight span.warningLabel')->text());
        $substance->setRidadr($safetyCrawler->filter('.safetyRight#RIDADR')->text());
        $substance->setWgkGermany($safetyCrawler->filter('.safetyRight#WGK\ Germany')->text());
        $p_statements = explode('-', $safetyCrawler->filter('.safetyRight#Precautionary\ statements')->text());
        array_walk($p_statements, function (&$statement) {
            $statement = $this->getStatement(trim($statement));
        });
        $h_statements = explode('-', $safetyCrawler->filter('.safetyRight#Hazard\ statements')->text());
        array_walk($h_statements, function (&$statement) {
            $statement = $this->getStatement(trim($statement));
        });
        $substance->setStatements(array_merge($p_statements, $h_statements));
    }

    /**
     * Get the symbol for a specified identifier
     * 
     * @param string $search
     * @return Symbol
     */
    protected function getSymbol(string $search): Symbol {
        $symbol = $this->signal_repo->findOneBy(array('name' => $search));
        if (!$symbol) {
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
    protected function getStatement(string $search): Statement {
        $statement = $this->statement_repo->findOneBy(array('name' => $search));
        if (!$statement) {
            $statement = new Statement();
            $statement->setName($search);
            switch (strtolower($search[0])) {
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

    public function curl($url) {
        $this->logger->info("Curling $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cookie: SialLocaleDef=CountryCode~CH|WebLang~-3|"));
        $return = curl_exec($ch);
        if (!$return || $return == "") {
            throw new \RuntimeException(curl_error($ch), curl_errno($ch));
        }
        curl_close($ch);
        $this->logger->info("website ($url) content", array('content' => $return));
        return $return;
    }

}
