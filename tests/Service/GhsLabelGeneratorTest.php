<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Statement;
use App\Entity\Substance;
use App\Entity\Symbol;
use App\Service\GhsLabelGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GhsLabelGeneratorTest extends TestCase
{
    public function testGenerateTexPopulatesSubstanceData(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $generator = new GhsLabelGenerator($projectDir, new NullLogger());

        $substance = new Substance();
        $substance->setName('Ethanol');
        $substance->setCASNumber('64-17-5');
        $substance->setFormula('C2H6O');
        $substance->setSignalWord('Danger');

        $symbol1 = new Symbol();
        $symbol1->setName('GHS02');
        $symbol2 = new Symbol();
        $symbol2->setName('GHS07');
        $substance->setSymbols([$symbol1, $symbol2]);

        $statement1 = new Statement();
        $statement1->setName('H225');
        $statement1->setDescription('Highly flammable liquid and vapour');
        $statement1->setType(Statement::TYPE_H);

        $statement2 = new Statement();
        $statement2->setName('P210');
        $statement2->setDescription('Keep away from heat');
        $statement2->setType(Statement::TYPE_P);

        $substance->setStatements([$statement1, $statement2]);

        $tex = $generator->generateTex($substance);

        $this->assertStringContainsString('\huge Ethanol', $tex);
        $this->assertStringContainsString('\newcommand{\Signal}{DANGER}', $tex);
        $this->assertStringContainsString('\newcommand{\FirstSymbol}{figures/flamme}', $tex);
        $this->assertStringContainsString('\newcommand{\SecondSymbol}{figures/exclam}', $tex);
        $this->assertStringContainsString('H225', $tex);
        $this->assertStringContainsString('P210', $tex);
        $this->assertStringContainsString('CAS: 64-17-5', $tex);
        $this->assertStringContainsString('Formula: C2H6O', $tex);
    }
}
