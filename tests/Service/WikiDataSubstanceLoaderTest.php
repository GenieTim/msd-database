<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Statement;
use App\Entity\Substance;
use App\Entity\Symbol;
use App\Repository\StatementRepository;
use App\Repository\SubstanceRepository;
use App\Repository\SymbolRepository;
use App\Service\WikiDataSubstanceLoader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WikiDataSubstanceLoaderTest extends TestCase
{
    public function testLoadSubstanceFromWikiData(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $substanceRepo = $this->createMock(SubstanceRepository::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $em->method('getRepository')->willReturnCallback(function (string $class) use ($substanceRepo, $statementRepo, $symbolRepo) {
            return match ($class) {
                Substance::class => $substanceRepo,
                Statement::class => $statementRepo,
                Symbol::class => $symbolRepo,
                default => null,
            };
        });

        $statementRepo->method('getMatching')->willReturnCallback(function (array $codes) {
            $statements = [];
            foreach ($codes as $code) {
                $st = new Statement();
                $st->setName($code);
                $statements[] = $st;
            }
            return $statements;
        });

        // Mock 1: Wikidata search for "acetone"
        // Mock 2: Entity data for Q4938
        $responses = [
            new MockResponse(json_encode([
                'search' => [
                    ['id' => 'Q4938', 'label' => 'acetone'],
                ],
            ])),
            new MockResponse(json_encode([
                'entities' => [
                    'Q4938' => [
                        'labels' => [
                            'en' => ['value' => 'acetone'],
                        ],
                        'claims' => [
                            'P231' => [ // CAS
                                ['mainsnak' => ['datavalue' => ['value' => '67-64-1']]],
                            ],
                            'P274' => [ // Formula
                                ['mainsnak' => ['datavalue' => ['value' => 'C3H6O']]],
                            ],
                            'P662' => [ // PubChem CID
                                ['mainsnak' => ['datavalue' => ['value' => '180']]],
                            ],
                            'P1033' => [ // Signal word
                                ['mainsnak' => ['datavalue' => ['value' => ['id' => 'Q11484089']]]], // Danger
                            ],
                            'P5040' => [ // Pictogram
                                ['mainsnak' => ['datavalue' => ['value' => ['id' => 'Q50379569']]]], // GHS02
                            ],
                            'P5041' => [ // H statements
                                ['mainsnak' => ['datavalue' => ['value' => 'H225']]],
                                ['mainsnak' => ['datavalue' => ['value' => 'H319']]],
                            ],
                        ],
                    ],
                ],
            ])),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new WikiDataSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('acetone');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('acetone', $substance->getName());
        $this->assertSame('67-64-1', $substance->getCASNumber());
        $this->assertSame('C3H6O', $substance->getFormula());
        $this->assertSame(180, $substance->getPubchemId());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertCount(1, $substance->getSymbols());
        $this->assertCount(2, $substance->getStatements());
    }
}
