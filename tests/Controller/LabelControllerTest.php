<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Substance;
use App\Repository\SubstanceRepository;
use App\Service\GhsLabelGenerator;
use App\Service\SubstanceLoaderInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LabelControllerTest extends WebTestCase
{
    public function testDownloadTexBySearch(): void
    {
        $client = static::createClient();

        $substance = new Substance();
        $substance->setName('Ethanol');
        $substance->setCASNumber('64-17-5');

        $mockLoader = $this->createMock(SubstanceLoaderInterface::class);
        $mockLoader->method('loadSubstance')->with('64-17-5')->willReturn($substance);

        $client->getContainer()->set('App\Service\SubstanceLoaderInterface', $mockLoader);

        $client->request('GET', '/substance/search/label/tex?search=64-17-5');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/x-tex; charset=utf-8');
        $this->assertStringContainsString('Ethanol', (string) $client->getResponse()->getContent());
    }

    public function testDownloadTexNotFoundThrows404(): void
    {
        $client = static::createClient();

        $mockLoader = $this->createMock(SubstanceLoaderInterface::class);
        $mockLoader->method('loadSubstance')->willReturn(null);

        $client->getContainer()->set('App\Service\SubstanceLoaderInterface', $mockLoader);

        $client->request('GET', '/substance/search/label/tex?search=unknown');

        $this->assertResponseStatusCodeSame(404);
    }
}
