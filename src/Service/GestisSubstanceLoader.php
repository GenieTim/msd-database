<?php
/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use Goutte\Client;
use App\Entity\Symbol;
use App\Entity\Statement;
use App\Entity\Substance;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Description of GestisSubstanceLoader
 *
 * @author timbernhard
 */
class GestisSubstanceLoader implements SubstanceLoaderInterface
{
  private $em;
  private $statement_repo;
  private $symbol_repo;
  private $substance_repo;
  private $logger;
  const BASE_URL = 'http://gestis-en.itrust.de/nxt/gateway.dll/gestis_en/000000.xml?f=templates$fn=default.htm$vid=gestiseng:sdbeng$3.0';

  public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
  {
    $this->em = $em;
    $this->logger = $logger;
    $this->symbol_repo = $em->getRepository(Symbol::class);
    $this->substance_repo = $em->getRepository(Substance::class);
    $this->statement_repo = $em->getRepository(Statement::class);
  }

  public function loadSubstance(string $search)
  {
    $substance = $this->substance_repo->findByAny($search);
    if (!$substance) {
      $results = $this->searchForSubstanceInGestis($search);
      if (count($results) !== 0) {
        $substances = array();
        foreach ($results as $resultUrl) {
          $s = $this->translateSubstance($resultUrl);
          // check for existance as translateSubstance() does not
          if (!($s = $this->substance_repo->findOneBy([
            'formula' => $substance->getFormula(),
            'rtecs' => $substance->getRtecs(),
            'pubchem_id' => $substance->getPubchemId(),
            'signal_word' => $substance->getSignalWord()
          ]))) {
            $this->em->persist($s);
          }
          $substance[] = $s;
        }
        $this->em->flush();
        $substance = $substances[0];
      }
    }
    return $substance;
  }

  public function searchForSubstanceInGestis(string $search)
  {
    $client = new Client();
    $crawler = $client->request('GET', self::BASE_URL);
    $form = $crawler->filter('iframe#banner form')->form();
    $form->setValues([
      'qeingabe' => $search // let's hope Gestis do sanitize their input :P
    ]);
    $crawler = $client->submit($form);
    $resultsFrame = $crawler->filter('iframe#main');
    $resultLinks = $resultsFrame->filter('td.hit-title a');
    $resultUrls = [];
    foreach ($resultLinks as $link) {
      $resultUrls[] = $resultsFrame->selectLink($link->textContent)->link()->getUri();
    }
  }

  public function translateSubstance(string $url)
  {
    $substance = new Substance();
    $client = new Client();
    $crawler = $client->request('GET', $url)->filter('frame[name=doc-body]');
    // set basics
    $substance->setName($crawler->filter('h1.stoffname')[0]->textContent);
    $substance->setFormula($crawler->filter('td span.acsf')[0]->textContent);
    // find signal word
    $signalWordTitle = $crawler->filterXPath('//*/td/b[text()[contains(.,"Signal Word:")]]');
    $substance->setSignalWord(end($signalWordTitle->parents()->siblings())->textContent);
    // find GHS Symbols applying
    $allImgs = $crawler->filter('img');
    $symbolNames = array();
    foreach ($allImgs as $img) {
      /**
       * @var \DOMElement $img
       */
      $src = $img->getAttribute('src');
      $imgName = end(explode('/', $src));
      $suffixlessImgName = trim(explode('.', $imgName)[0]);
      if (strpos($suffixlessImgName, 'GH') === 0) {
        $symbolNames[] = $suffixlessImgName;
      }
    }
    // find H-sentences
    $hTitle = $crawler->filterXPath('//*/td/b[text()[contains(.,"Hazard Statement - H-phrases:")]]');
    $hTd = $hTitle->parents()->parents()->parents()->children('tr td')[1]; // b > td > tr > tbody < tr
    // find P-sentences
    $pTitle = $crawler->filterXPath('//*/td/b[text()[contains(.,"Precautionary Statement - P-phrases:")]]');
    $pTd = $pTitle->parents()->parents()->parents()->children('tr td')[1]; // b > td > tr > tbody < tr
    // set statements
    $substance->setStatements(array_merge($this->statement_repo->getMatching($hTd->textContent), $this->statement_repo->getMatching($pTd->textContent)));
    // find CAS number
    $nrTable = $crawler->filter('table.stoffnummern');
    $casTd = $nrTable->filterXPath('//*/td/b[text()[contains(.,"CAS")]]');
    $substance->setCASNumber($casTd->parents()->siblings()->filter('span')[0]->textContent);
    // find WGK
    $wgkTd = $crawler->filterXPath('//*/td[text()[contains(.,"WGK")]]');
    if ($wgkTd->count() > 0) {
      preg_match('/WGK ([0-9].)/', $wgkTd[0]->textContent, $pregRes);
      $substance->setWgkGermany(end($pregRes));
    }
    $substance->setSource($url);
    return $substance;
  }
}
