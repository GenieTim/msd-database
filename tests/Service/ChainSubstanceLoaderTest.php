<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Statement;
use App\Entity\Substance;
use App\Entity\Symbol;
use App\Repository\SubstanceRepository;
use App\Service\ChainSubstanceLoader;
use App\Service\SubstanceLoaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ChainSubstanceLoaderTest extends TestCase
{
    public function testReturnsCachedSubstanceFromDatabase(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $substanceRepo = $this->createStub(SubstanceRepository::class);
        $em->method('getRepository')->willReturn($substanceRepo);

        $cached = new Substance();
        $cached->setName('Cached Substance');
        $substanceRepo->method('findByAny')->willReturn($cached);

        $mockLoader = $this->createMock(SubstanceLoaderInterface::class);
        $mockLoader->expects($this->never())->method('loadSubstance');

        $chain = new ChainSubstanceLoader([$mockLoader], $em, new NullLogger());
        $result = $chain->loadSubstance('test');

        $this->assertSame($cached, $result);
    }

    public function testExecutesChainAndEnrichesMissingData(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $substanceRepo = $this->createStub(SubstanceRepository::class);
        $em->method('getRepository')->willReturn($substanceRepo);
        $substanceRepo->method('findByAny')->willReturn(null);
        $substanceRepo->method('findOneBy')->willReturn(null);

        // Loader 1 provides name, CAS, formula, but missing statements and symbols
        $loader1 = $this->createStub(SubstanceLoaderInterface::class);
        $loader1->method('supports')->willReturn(true);
        $substance1 = new Substance();
        $substance1->setName('Ethanol');
        $substance1->setFormula('C2H6O');
        $substance1->setCASNumber('64-17-5');
        $loader1->method('loadSubstance')->willReturn($substance1);

        // Loader 2 provides statements, symbols, signal word
        $loader2 = $this->createStub(SubstanceLoaderInterface::class);
        $loader2->method('supports')->willReturn(true);
        $substance2 = new Substance();
        $substance2->setName('Ethanol');
        $substance2->setSignalWord('Danger');
        $statement = new Statement();
        $statement->setName('H225');
        $substance2->addStatement($statement);
        $symbol = new Symbol();
        $symbol->setName('GHS02');
        $substance2->addSymbol($symbol);
        $loader2->method('loadSubstance')->willReturn($substance2);

        $chain = new ChainSubstanceLoader([$loader1, $loader2], $em, new NullLogger());
        $result = $chain->loadSubstance('ethanol');

        $this->assertInstanceOf(Substance::class, $result);
        $this->assertSame('Ethanol', $result->getName());
        $this->assertSame('64-17-5', $result->getCASNumber());
        $this->assertSame('C2H6O', $result->getFormula());
        $this->assertSame('Danger', $result->getSignalWord());
        $this->assertCount(1, $result->getStatements());
        $this->assertCount(1, $result->getSymbols());
    }
}
