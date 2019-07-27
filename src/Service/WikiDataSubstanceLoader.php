<?php

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Entity\Symbol;
use Wikidata\Wikidata;
use App\Entity\Statement;
use App\Entity\Substance;
use Wikidata\SearchResult;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Tightenco\Collect\Support\Collection;

/**
 * Description of WikiDataSubstanceLoader
 *
 * @author timbernhard
 */
class WikiDataSubstanceLoader implements SubstanceLoaderInterface
{
  private $em;
  private $statement_repo;
  private $signal_repo;
  private $substance_repo;
  private $logger;
  private $api_client;
  const LANGUAGE = 'en';

  public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
  {
    $this->em = $em;
    $this->logger = $logger;
    $this->signal_repo = $em->getRepository(Symbol::class);
    $this->substance_repo = $em->getRepository(Substance::class);
    $this->statement_repo = $em->getRepository(Statement::class);
    $this->api_client = new Wikidata();
  }

  public function loadSubstance(string $search)
  {
    $substance = $this->substance_repo->findByAny($search);
    if (!$substance) {
      $results = $this->findSubstancesInApi($search);
      if (count($results) === 0) {
        $this->logger->warning('no results found by ' . self::class . ' in search for ' . $search);
      } else {
        $allSubstances = array();
        foreach ($results as $searchResult) {
          /**
           * @var SearchResult $searchResult
           */
          $allSubstances[] = $this->translateApiIdToSubstance($searchResult->id);
        }
        // return just first one?!?
        $substance = $allSubstances[0];
      }
    }
    return $substance;
  }

  /**
   * Find all substance results by search for a string
   *
   * @param string $search
   * @return SearchResult[]
   */
  public function findSubstancesInApi(string $search)
  {
    $results = $this->api_client->search($search, self::LANGUAGE, 10);
    return $results;
  }

  /**
   * Load the necessary properties of a substance by id
   *
   * @param string $apiId
   * @return Substance
   */
  public function translateApiIdToSubstance(string $apiId): Substance
  {
    $substance = new Substance();
    $wikiDataEntity = $this->api_client->get($apiId, self::LANGUAGE);
    dump($wikiDataEntity); die();
    $translationKey = []; // unfortunately, I was not able to find an entity with the data we are looking for
    return $substance;
  }
}
