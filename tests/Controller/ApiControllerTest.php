<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Substance;
use App\Service\SubstanceLoaderInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
{
    public function testGetSubstanceJson(): void
    {
        $client = static::createClient();

        $substance = new Substance();
        $substance->setName('Ethanol');
        $substance->setFormula('C2H6O');
        $substance->setCASNumber('64-17-5');

        $mockLoader = $this->createMock(SubstanceLoaderInterface::class);
        $mockLoader->method('loadSubstance')->with('ethanol')->willReturn($substance);

        $client->getContainer()->set('App\Service\SubstanceLoaderInterface', $mockLoader);

        $client->request('GET', '/api/json/substance?search=ethanol');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Ethanol', (string) $client->getResponse()->getContent());
    }

    public function testGetOpenApiSpec(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/spec.json');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $content = (string) $client->getResponse()->getContent();
        $data = json_decode($content, true);

        $this->assertSame('3.1.0', $data['openapi']);
        $this->assertArrayHasKey('/api/{format}/substance', $data['paths']);
    }

    public function testGetApiDoc(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('SwaggerUIBundle', (string) $client->getResponse()->getContent());
    }
}
