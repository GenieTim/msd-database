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
  private $symbol_repo;
  private $substance_repo;
  private $logger;
  private $api_client;
  const LANGUAGE = 'en';

  public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
  {
    $this->em = $em;
    $this->logger = $logger;
    $this->symbol_repo = $em->getRepository(Symbol::class);
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
    // CAS: P231
    // PubchemCID: P662
    // formula: P274
    // rtecs: P657
    $translationKey = [
      'CASNumber' => 'P231',
      "PubchemId" => "P662",
      "Formula" => "P274",
      "Rtecs" => "P657",
      "SignalWord" => "P1033"
    ];
    // get/set single properties
    foreach ($translationKey as $key => $value) {
      if (isset($wikiDataEntity->properties[$value])) {
        call_user_func(array($substance, 'set' . $key), $wikiDataEntity->properties[$value]->value);
      }
    }
    // Properties: childs of safety P4952 : 
    // P-statement: P5042
    // H-statement: P5041
    // signal word: P1033
    // GHS pictogram:   P5040
    $safetyData = $wikiDataEntity->properties['P4952'];
    // if (isset($safetyData)) // access children

    // assuming here: many-to-one are all set correctly
    if (($s = $this->substance_repo->findOneBy([
      'formula' => $substance->getFormula(),
      'rtecs' => $substance->getRtecs(),
      'pubchem_id' => $substance->getPubchemId(),
      'signal_word' => $substance->getSignalWord()
    ]))) {
      return $s;
    }
    // get/set properties with many-to-one relation
    $statements = array();
    if (isset($wikiDataEntity->properties["P5041"])) {
      $hStatements = $wikiDataEntity->properties["P5041"]->value;
      var_dump($hStatements);
      $statements = array_merge($this->statement_repo->getMatching($hStatements), $statements);
    }
    if (isset($wikiDataEntity->properties["P5042"])) {
      $pStatements = $wikiDataEntity->properties["P5042"]->value;
      var_dump($pStatements);
      $statements = array_merge($this->statement_repo->getMatching($pStatements), $statements);
    }
    $substance->setStatements($statements);
    if (isset($wikiDataEntity->properties["P5040"])) {
      $symbol = $wikiDataEntity->properties["P5040"]->value;
      // caution: we just assume we get only one symbol, which may be wrong in many cases
      $symbolName = (split(':', $symbol))[0];
      $savedSymbol = $this->symbol_repo->findOneBy(['name' => $symbolName]);
      $substance->setSymbols([$savedSymbol]);
    }
    $substance->setSource('https://www.wikidata.org/wiki/' . $wikiDataEntity->id);
    $substance->setName($wikiDataEntity->label);

    // persist the new object
    // $this->em->persist($substance);
    // $this->em->flush();

    return $substance;
  }
}
