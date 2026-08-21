<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Tests\Service;

use App\Entity\Statement;
use App\Entity\Substance;
use App\Entity\Symbol;
use App\Repository\StatementRepository;
use App\Repository\SymbolRepository;
use App\Service\EchaSubstanceLoader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EchaSubstanceLoaderTest extends TestCase
{
    public function testSupports(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);
        $httpClient = new MockHttpClient();

        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $this->assertTrue($loader->supports('64-17-5'));
        $this->assertTrue($loader->supports('200-578-6'));
        $this->assertTrue($loader->supports('Ethanol'));
        $this->assertFalse($loader->supports(''));
        $this->assertFalse($loader->supports('   '));
    }

    public function testLoadSubstanceByCasNumberSuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $symbolRepo->method('findOneBy')->willReturnCallback(function (array $criteria) {
            $sym = new Symbol();
            $sym->setName($criteria['name']);
            return $sym;
        });

        $statementRepo->method('findOneBy')->willReturnCallback(function (array $criteria) {
            $stmt = new Statement();
            $stmt->setName($criteria['name']);
            return $stmt;
        });

        $searchResponse = new MockResponse(json_encode([
            'results' => [
                [
                    'id' => '100.000.526',
                    'name' => 'Ethanol',
                    'cas_number' => '64-17-5',
                    'ec_number' => '200-578-6',
                    'formula' => 'C2H6O',
                    'clp' => [
                        'signal_word' => 'Danger',
                        'pictograms' => ['GHS02', 'GHS07'],
                        'hazard_statements' => [
                            ['code' => 'H225', 'description' => 'Highly flammable liquid and vapour'],
                            ['code' => 'H319', 'description' => 'Causes serious eye irritation'],
                        ],
                        'precautionary_statements' => ['P210', 'P233', 'P280', 'P305+P351+P338'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertSame('https://echa.europa.eu/substance-information/-/substanceinfo/100.000.526', $substance->getSource());
        $this->assertCount(2, $substance->getSymbols());
        $this->assertCount(6, $substance->getStatements()); // 2 H-statements + 4 P-statements
    }

    public function testLoadSubstanceByEcNumberSuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $symbolRepo->method('findOneBy')->willReturn(null);
        $statementRepo->method('findOneBy')->willReturn(null);

        $searchResponse = new MockResponse(json_encode([
            [
                'name' => 'Acetone',
                'casNumber' => '67-64-1',
                'ecNumber' => '200-662-2',
                'molecularFormula' => 'C3H6O',
                'classification' => [
                    'signalWord' => 'Danger',
                    'symbols' => ['GHS02', 'GHS07'],
                    'hazardStatements' => [
                        'H225: Highly flammable liquid and vapour',
                        'H319: Causes serious eye irritation',
                        'H336: May cause drowsiness or dizziness',
                        'EUH066: Repeated exposure may cause skin dryness or cracking',
                    ],
                    'precautionaryStatements' => [
                        'P210',
                        'P261',
                        'P305+P351+P338',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('200-662-2');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Acetone', $substance->getName());
        $this->assertSame('67-64-1', $substance->getCASNumber());
        $this->assertSame('C3H6O', $substance->getFormula());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertSame('https://echa.europa.eu/substance-information/-/substanceinfo/200-662-2', $substance->getSource());
        $this->assertCount(2, $substance->getSymbols());
        $this->assertCount(7, $substance->getStatements()); // 4 H/EUH statements + 3 P statements
    }

    public function testLoadSubstanceByChemicalNameSuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $symbolRepo->method('findOneBy')->willReturn(null);
        $statementRepo->method('findOneBy')->willReturn(null);

        // Multiple results, loader should resolve the exact name match
        $searchResponse = new MockResponse(json_encode([
            'data' => [
                [
                    'substanceName' => 'Sodium hypochlorite',
                    'casNumber' => '7681-52-9',
                    'ecNumber' => '231-668-3',
                    'classification' => [
                        'signalWord' => 'Danger',
                        'symbols' => ['GHS05', 'GHS09'],
                        'hazardStatements' => ['H314', 'H400'],
                        'precautionaryStatements' => ['P260', 'P273', 'P280'],
                    ],
                ],
                [
                    'substanceName' => 'Sodium hydroxide',
                    'casNumber' => '1310-73-2',
                    'ecNumber' => '215-185-5',
                    'formula' => 'HNaO',
                    'classification' => [
                        'signalWord' => 'Danger',
                        'symbols' => ['GHS05'],
                        'hazardStatements' => [
                            ['code' => 'H314', 'description' => 'Causes severe skin burns and eye damage'],
                        ],
                        'precautionaryStatements' => [
                            ['code' => 'P260', 'description' => 'Do not breathe dust/fume/gas/mist/vapours/spray.'],
                            ['code' => 'P280', 'description' => 'Wear protective gloves/clothing/eye protection/face protection.'],
                            ['code' => 'P305+P351+P338', 'description' => 'IF IN EYES: Rinse cautiously with water for several minutes.'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Sodium hydroxide');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Sodium hydroxide', $substance->getName());
        $this->assertSame('1310-73-2', $substance->getCASNumber());
        $this->assertSame('HNaO', $substance->getFormula());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertSame('https://echa.europa.eu/substance-information/-/substanceinfo/215-185-5', $substance->getSource());
        $this->assertCount(1, $substance->getSymbols());
        $this->assertCount(4, $substance->getStatements());
    }

    public function testLoadSubstanceWithSeparateDetailsEndpoint(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $symbolRepo->method('findOneBy')->willReturn(null);
        $statementRepo->method('findOneBy')->willReturn(null);

        // 1. Initial search response with ID only (no CLP data)
        $searchResponse = new MockResponse(json_encode([
            'results' => [
                [
                    'id' => '100.000.526',
                    'name' => 'Ethanol',
                    'cas_number' => '64-17-5',
                    'ec_number' => '200-578-6',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        // 2. Detail response with full CLP classification
        $detailResponse = new MockResponse(json_encode([
            'id' => '100.000.526',
            'name' => 'Ethanol',
            'cas_number' => '64-17-5',
            'ec_number' => '200-578-6',
            'formula' => 'C2H6O',
            'clp' => [
                'signal_word' => 'Danger',
                'pictograms' => ['GHS02', 'GHS07'],
                'hazard_statements' => ['H225', 'H319'],
                'precautionary_statements' => ['P210', 'P280'],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse, $detailResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Ethanol');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertSame('https://echa.europa.eu/substance-information/-/substanceinfo/100.000.526', $substance->getSource());
        $this->assertCount(2, $substance->getSymbols());
        $this->assertCount(4, $substance->getStatements());
    }

    public function testLoadSubstanceHarmonisedClassificationFormat(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $symbolRepo->method('findOneBy')->willReturn(null);
        $statementRepo->method('findOneBy')->willReturn(null);

        $response = new MockResponse(json_encode([
            'name' => 'Methanol',
            'identifiers' => [
                'casNumber' => '67-56-1',
                'ecNumber' => '200-659-6',
                'formula' => 'CH4O',
                'infocardId' => '100.000.600',
            ],
            'harmonisedClassification' => [
                'signalWord' => 'Danger',
                'hazardPictograms' => ['GHS02: Flame', 'GHS06: Skull and crossbones', 'GHS08: Health hazard'],
                'hazardStatements' => [
                    'H225: Highly flammable liquid and vapour',
                    'H301: Toxic if swallowed',
                    'H311: Toxic in contact with skin',
                    'H331: Toxic if inhaled',
                    'H370: Causes damage to organs',
                ],
                'precautionaryStatements' => [
                    'P210',
                    'P260',
                    'P280',
                    'P301+P310',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('67-56-1');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Methanol', $substance->getName());
        $this->assertSame('67-56-1', $substance->getCASNumber());
        $this->assertSame('CH4O', $substance->getFormula());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertSame('https://echa.europa.eu/substance-information/-/substanceinfo/100.000.600', $substance->getSource());
        $this->assertCount(3, $substance->getSymbols());
        $this->assertCount(9, $substance->getStatements()); // 5 H-statements + 4 P-statements
    }

    public function testLoadSubstanceExistingSymbolAndStatementReused(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $existingSymbol = new Symbol();
        $existingSymbol->setName('GHS02');

        $existingStatement = new Statement();
        $existingStatement->setName('H225');
        $existingStatement->setType(Statement::TYPE_H);

        $symbolRepo->method('findOneBy')->with(['name' => 'GHS02'])->willReturn($existingSymbol);
        $statementRepo->method('findOneBy')->with(['name' => 'H225'])->willReturn($existingStatement);

        // Expect persist to NOT be called for the existing symbol and statement
        $em->expects($this->never())->method('persist');

        $response = new MockResponse(json_encode([
            'name' => 'Test Chemical',
            'cas_number' => '123-45-6',
            'clp' => [
                'signal_word' => 'Warning',
                'symbols' => ['GHS02'],
                'hazard_statements' => ['H225'],
                'precautionary_statements' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('123-45-6');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Warning', $substance->getSignalWord());
        $this->assertCount(1, $substance->getSymbols());
        $this->assertSame($existingSymbol, $substance->getSymbols()->first());
        $this->assertCount(1, $substance->getStatements());
        $this->assertSame($existingStatement, $substance->getStatements()->first());
    }

    public function testLoadSubstanceReturnsNullWhenNoResultsFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $searchResponse = new MockResponse(json_encode(['results' => []], JSON_THROW_ON_ERROR));
        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('nonexistent_chemical_xyz');
        $this->assertNull($substance);
    }

    public function testLoadSubstanceHttp404ReturnsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $searchResponse = new MockResponse('Not Found', ['http_code' => 404]);
        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');
        $this->assertNull($substance);
    }

    public function testLoadSubstanceHttp500ReturnsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $searchResponse = new MockResponse('Internal Server Error', ['http_code' => 500]);
        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');
        $this->assertNull($substance);
    }

    public function testLoadSubstanceNetworkExceptionReturnsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);

        $searchResponse = new MockResponse('', [
            'error' => new TransportException('Connection timeout to ECHA API'),
        ]);
        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');
        $this->assertNull($substance);
    }

    public function testLoadSubstanceEmptySearchReturnsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $statementRepo = $this->createMock(StatementRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);
        $httpClient = new MockHttpClient();

        $loader = new EchaSubstanceLoader($em, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('   ');
        $this->assertNull($substance);
    }
}
