<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Substance;
use App\Twig\ChemicalExtension;
use PHPUnit\Framework\TestCase;

class ChemicalExtensionTest extends TestCase
{
    public function testGetStructureUrlWithPubchemId(): void
    {
        $extension = new ChemicalExtension();
        $substance = new Substance();
        $substance->setPubchemId(702);

        $url = $extension->getStructureUrl($substance, 300);
        $this->assertSame('https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/CID/702/PNG?image_size=300x300', $url);
    }

    public function testGetStructureUrlWithoutPubchemIdReturnsNull(): void
    {
        $extension = new ChemicalExtension();
        $substance = new Substance();

        $this->assertNull($extension->getStructureUrl($substance));
        $this->assertNull($extension->getStructureUrl(null));
    }

    public function testSymbolBadgeClass(): void
    {
        $extension = new ChemicalExtension();
        $this->assertStringContainsString('bg-danger', $extension->getSymbolBadgeClass('GHS02'));
        $this->assertStringContainsString('bg-dark', $extension->getSymbolBadgeClass('GHS06'));
        $this->assertStringContainsString('bg-warning', $extension->getSymbolBadgeClass('GHS05'));
        $this->assertStringContainsString('bg-info', $extension->getSymbolBadgeClass('GHS07'));
    }

    public function testSignalWordBadgeClass(): void
    {
        $extension = new ChemicalExtension();
        $this->assertStringContainsString('bg-danger', $extension->getSignalWordBadgeClass('Danger'));
        $this->assertStringContainsString('bg-warning', $extension->getSignalWordBadgeClass('Warning'));
        $this->assertStringContainsString('bg-secondary', $extension->getSignalWordBadgeClass(null));
    }
}
