<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Substance;
use App\Service\ChebiSubstanceLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ChebiSubstanceLoaderTest extends TestCase
{
    public function testLoadSubstanceByChebiId(): void
    {
        $termResponse = new MockResponse(json_encode([
            'label' => 'ethanol',
            'obo_id' => 'CHEBI:16236',
            'iri' => 'http://purl.obolibrary.org/obo/CHEBI_16236',
            'annotation' => [
                'formula' => ['C2H6O'],
                'database_cross_reference' => ['cas:64-17-5', 'drugbank:DB00898'],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$termResponse]);
        $loader = new ChebiSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('CHEBI:16236');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('https://www.ebi.ac.uk/chebi/searchId.do?chebiId=CHEBI%3A16236', $substance->getSource());
    }

    public function testLoadSubstanceByNameWithSearchAndTermDetail(): void
    {
        $searchResponse = new MockResponse(json_encode([
            'response' => [
                'numFound' => 1,
                'docs' => [
                    [
                        'label' => 'ethanol',
                        'obo_id' => 'CHEBI:16236',
                        'iri' => 'http://purl.obolibrary.org/obo/CHEBI_16236',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $termResponse = new MockResponse(json_encode([
            'label' => 'ethanol',
            'obo_id' => 'CHEBI:16236',
            'iri' => 'http://purl.obolibrary.org/obo/CHEBI_16236',
            'annotation' => [
                'http://purl.obolibrary.org/obo/chebi/formula' => ['C2H6O'],
                'database_cross_reference' => ['CAS:64-17-5'],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse, $termResponse]);
        $loader = new ChebiSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('ethanol');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('64-17-5', $substance->getCASNumber());
    }

    public function testLoadSubstanceByCasNumber(): void
    {
        $searchResponse = new MockResponse(json_encode([
            'response' => [
                'numFound' => 1,
                'docs' => [
                    [
                        'label' => 'ethanol',
                        'obo_id' => 'CHEBI:16236',
                        'iri' => 'http://purl.obolibrary.org/obo/CHEBI_16236',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $termResponse = new MockResponse(json_encode([
            'label' => 'ethanol',
            'obo_id' => 'CHEBI:16236',
            'annotation' => [
                'formula' => ['C2H6O'],
                'database_cross_reference' => ['cas:64-17-5'],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse, $termResponse]);
        $loader = new ChebiSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
    }

    public function testLoadSubstanceReturnsNullWhenNoResultsFound(): void
    {
        $searchResponse = new MockResponse(json_encode([
            'response' => [
                'numFound' => 0,
                'docs' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$searchResponse]);
        $loader = new ChebiSubstanceLoader($httpClient, new NullLogger());

        $result = $loader->loadSubstance('unknown_substance_xyz');
        $this->assertNull($result);
    }

    public function testLoadSubstanceReturnsNullOnHttpError(): void
    {
        $errorResponse = new MockResponse('Server Error', ['http_code' => 500]);
        $httpClient = new MockHttpClient([$errorResponse]);
        $loader = new ChebiSubstanceLoader($httpClient, new NullLogger());

        $result = $loader->loadSubstance('ethanol');
        $this->assertNull($result);
    }

    public function testSupports(): void
    {
        $loader = new ChebiSubstanceLoader(new MockHttpClient(), new NullLogger());
        $this->assertTrue($loader->supports('ethanol'));
        $this->assertFalse($loader->supports('   '));
        $this->assertNull($loader->loadSubstance('   '));
    }
}
