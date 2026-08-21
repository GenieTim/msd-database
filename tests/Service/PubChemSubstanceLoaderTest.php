<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Statement;
use App\Entity\Substance;
use App\Entity\Symbol;
use App\Repository\StatementRepository;
use App\Repository\SubstanceRepository;
use App\Repository\SymbolRepository;
use App\Service\PubChemSubstanceLoader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PubChemSubstanceLoaderTest extends TestCase
{
    public function testLoadSubstanceSuccess(): void
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

        // 1. Initial DB check -> not found
        $substanceRepo->method('findByAny')->willReturn(null);
        $substanceRepo->method('findOneBy')->willReturn(null);

        // Mock HTTP client responses for PubChem API calls:
        // 1) CID lookup for "64-17-5"
        // 2) Properties lookup for CID 702
        // 3) Synonyms lookup for CID 702
        // 4) GHS classification lookup for CID 702
        $responses = [
            new MockResponse(json_encode([
                'IdentifierList' => ['CID' => [702]],
            ])),
            new MockResponse(json_encode([
                'PropertyTable' => [
                    'Properties' => [[
                        'CID' => 702,
                        'MolecularFormula' => 'C2H6O',
                        'MolecularWeight' => 46.07,
                        'CanonicalSMILES' => 'CCO',
                        'InChI' => 'InChI=1S/C2H6O/c1-2-3/h3H,2H2,1H3',
                        'InChIKey' => 'LFQSCWFLJHTTHZ-UHFFFAOYSA-N',
                        'IUPACName' => 'ethanol',
                        'Title' => 'Ethanol',
                    ]],
                ],
            ])),
            new MockResponse(json_encode([
                'InformationList' => [
                    'Information' => [[
                        'CID' => 702,
                        'Synonym' => ['ethanol', '64-17-5'],
                    ]],
                ],
            ])),
            new MockResponse(json_encode([
                'Record' => [
                    'RecordNumber' => 702,
                    'Section' => [
                        [
                            'TOCHeading' => 'GHS Classification',
                            'Information' => [
                                [
                                    'Name' => 'Signal',
                                    'Value' => ['StringWithMarkup' => [['String' => 'Danger']]],
                                ],
                                [
                                    'Name' => 'Pictogram(s)',
                                    'Value' => [
                                        'StringWithMarkup' => [[
                                            'Markup' => [
                                                ['Extra' => 'GHS02: Flame', 'URL' => 'https://pubchem.ncbi.nlm.nih.gov/images/ghs/GHS02.svg'],
                                                ['Extra' => 'GHS07: Exclamation mark', 'URL' => 'https://pubchem.ncbi.nlm.nih.gov/images/ghs/GHS07.svg'],
                                            ],
                                        ]],
                                    ],
                                ],
                                [
                                    'Name' => 'GHS Hazard Statements',
                                    'Value' => [
                                        'StringWithMarkup' => [
                                            ['String' => 'H225: Highly Flammable liquid and vapor [Danger Flammable liquids]'],
                                            ['String' => 'H319: Causes serious eye irritation [Warning Eye irritation]'],
                                        ],
                                    ],
                                ],
                                [
                                    'Name' => 'Precautionary Statement Codes',
                                    'Value' => [
                                        'StringWithMarkup' => [
                                            ['String' => 'P210, P233, P280, P305+P351+P338'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new PubChemSubstanceLoader($em, $substanceRepo, $statementRepo, $symbolRepo, $httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame(46.07, $substance->getMolecularWeight());
        $this->assertSame('CCO', $substance->getSmiles());
        $this->assertSame('InChI=1S/C2H6O/c1-2-3/h3H,2H2,1H3', $substance->getInchi());
        $this->assertSame('LFQSCWFLJHTTHZ-UHFFFAOYSA-N', $substance->getInchikey());
        $this->assertSame(702, $substance->getPubchemId());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('Danger', $substance->getSignalWord());
        $this->assertCount(2, $substance->getSymbols());
        $this->assertCount(6, $substance->getStatements()); // 2 H-statements + 4 P-statements
    }
}
