<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Statement;
use App\Entity\Symbol;
use App\Repository\StatementRepository;
use App\Repository\SymbolRepository;
use App\Service\GestisSubstanceLoader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GestisSubstanceLoaderTest extends TestCase
{
    public function testLoadSubstanceWithValidGestisApiResponse(): void
    {
        $searchResponse = new MockResponse(json_encode([
            [
                'cas_nr' => '64-17-5',
                'name' => 'Ethanol',
                'zvg_nr' => '010420',
            ],
        ], JSON_THROW_ON_ERROR));

        $articleResponse = new MockResponse(json_encode([
            'name' => 'Ethanol',
            'hauptkapitel' => [
                [
                    'text' => 'Some intro',
                    'unterkapitel' => [
                        [
                            'text' => 'GHS CLASSIFICATION: <img src="ghs02.gif" /> <img src="ghs07.gif" /> Signal Word: "Danger" H225: Highly flammable liquid. H319: Causes serious eye irritation. P210: Keep away from heat. P305+P351+P338: In eyes rinse. WGK 1 - low hazard',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse, $articleResponse]);

        $symbolRepo = $this->createMock(SymbolRepository::class);
        $symbolRepo->method('findOneBy')->willReturnCallback(function (array $criteria) {
            $sym = new Symbol();
            $sym->setName($criteria['name']);
            return $sym;
        });

        $statementRepo = $this->createMock(StatementRepository::class);
        $statementRepo->method('findOneBy')->willReturnCallback(function (array $criteria) {
            $stmt = new Statement();
            $stmt->setName($criteria['name']);
            return $stmt;
        });

        $em = $this->createMock(EntityManagerInterface::class);

        $loader = new GestisSubstanceLoader(
            $em,
            $statementRepo,
            $symbolRepo,
            $httpClient,
            new NullLogger()
        );

        $substance = $loader->loadSubstance('64-17-5');

        $this->assertNotNull($substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertSame(1, $substance->getWgkGermany());
        $this->assertCount(2, $substance->getSymbols());
        $this->assertCount(4, $substance->getStatements());
    }

    public function testLoadSubstanceReturnsNullWhenNoResultsFound(): void
    {
        $searchResponse = new MockResponse(json_encode([], JSON_THROW_ON_ERROR));
        $httpClient = new MockHttpClient([$searchResponse]);

        $symbolRepo = $this->createMock(SymbolRepository::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $loader = new GestisSubstanceLoader(
            $em,
            $statementRepo,
            $symbolRepo,
            $httpClient,
            new NullLogger()
        );

        $substance = $loader->loadSubstance('nonexistent_chemical_99999');
        $this->assertNull($substance);
    }
}
