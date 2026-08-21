<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Substance;
use App\Service\CompToxSubstanceLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CompToxSubstanceLoaderTest extends TestCase
{
    public function testSupports(): void
    {
        $loader = new CompToxSubstanceLoader(new MockHttpClient(), new NullLogger());

        $this->assertTrue($loader->supports('ethanol'));
        $this->assertTrue($loader->supports('64-17-5'));
        $this->assertTrue($loader->supports('DTXSID7020182'));
        $this->assertTrue($loader->supports('C2H6O'));
        $this->assertFalse($loader->supports(''));
        $this->assertFalse($loader->supports('   '));
        $this->assertFalse($loader->supports("\n\t"));
    }

    public function testLoadSubstanceByCasSuccess(): void
    {
        $response = new MockResponse(json_encode([
            [
                'dtxsid' => 'DTXSID7020182',
                'preferredName' => 'Ethanol',
                'casrn' => '64-17-5',
                'molFormula' => 'C2H6O',
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceByNameSuccess(): void
    {
        $response = new MockResponse(json_encode([
            [
                'dtxsid' => 'DTXSID2021778',
                'preferredName' => 'Acetone',
                'casrn' => '67-64-1',
                'molFormula' => 'C3H6O',
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Acetone');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Acetone', $substance->getName());
        $this->assertSame('67-64-1', $substance->getCASNumber());
        $this->assertSame('C3H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID2021778', $substance->getSource());
    }

    public function testLoadSubstanceByDtxsidSuccess(): void
    {
        $response = new MockResponse(json_encode([
            [
                'dtxsid' => 'DTXSID7020182',
                'preferredName' => 'Ethanol',
                'casrn' => '64-17-5',
                'molFormula' => 'C2H6O',
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('DTXSID7020182');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceByDtxsidFallbackToByDtxsidEndpoint(): void
    {
        // 1st request to search/equal fails with 404
        // 2nd request to detail/search/by-dtxsid succeeds with single object
        $responses = [
            new MockResponse('', ['http_code' => 404]),
            new MockResponse(json_encode([
                'dtxsid' => 'DTXSID7020182',
                'preferredName' => 'Ethanol',
                'casrn' => '64-17-5',
                'molFormula' => 'C2H6O',
            ], JSON_THROW_ON_ERROR)),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('DTXSID7020182');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceByFormulaSuccess(): void
    {
        $response = new MockResponse(json_encode([
            [
                'dtxsid' => 'DTXSID7020182',
                'preferredName' => 'Ethanol',
                'casrn' => '64-17-5',
                'molFormula' => 'C2H6O',
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('C2H6O');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceByFormulaFallback(): void
    {
        // 1st request to search/equal fails with 404
        // 2nd request to msready/search/by-formula succeeds
        $responses = [
            new MockResponse('', ['http_code' => 404]),
            new MockResponse(json_encode([
                [
                    'dtxsid' => 'DTXSID7020182',
                    'preferredName' => 'Ethanol',
                    'casrn' => '64-17-5',
                    'molFormula' => 'C2H6O',
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('C2H6O');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceByNameFallbackStartWith(): void
    {
        // 1st request to search/equal fails with 404
        // 2nd request to search/start-with succeeds
        $responses = [
            new MockResponse('', ['http_code' => 404]),
            new MockResponse(json_encode([
                [
                    'dtxsid' => 'DTXSID7020182',
                    'preferredName' => 'Ethanol',
                    'casrn' => '64-17-5',
                    'molFormula' => 'C2H6O',
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Ethan');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
    }

    public function testLoadSubstanceByNameFallbackContain(): void
    {
        // 1st request to search/equal fails with 404
        // 2nd request to search/start-with fails with 404
        // 3rd request to search/contain succeeds
        $responses = [
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 404]),
            new MockResponse(json_encode([
                [
                    'dtxsid' => 'DTXSID7020182',
                    'preferredName' => 'Ethanol',
                    'casrn' => '64-17-5',
                    'molFormula' => 'C2H6O',
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('than');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
    }

    public function testLoadSubstanceReturnsNullOnEmptySearch(): void
    {
        $httpClient = new MockHttpClient();
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $this->assertNull($loader->loadSubstance(''));
        $this->assertNull($loader->loadSubstance('   '));
        $this->assertSame(0, $httpClient->getRequestsCount());
    }

    public function testLoadSubstanceReturnsNullOnEmptyApiResponse(): void
    {
        $responses = [
            new MockResponse(json_encode([], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([], JSON_THROW_ON_ERROR)),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $this->assertNull($loader->loadSubstance('nonexistent_chemical'));
    }

    public function testLoadSubstanceHandlesRateLimit(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('rate limit reached'));

        $responses = [
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('', ['http_code' => 429]),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new CompToxSubstanceLoader($httpClient, $logger);

        $this->assertNull($loader->loadSubstance('Ethanol'));
    }

    public function testLoadSubstanceHandlesHttpErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('CompTox API returned HTTP 500'));

        $responses = [
            new MockResponse('Internal Server Error', ['http_code' => 500]),
            new MockResponse('Internal Server Error', ['http_code' => 500]),
            new MockResponse('Internal Server Error', ['http_code' => 500]),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new CompToxSubstanceLoader($httpClient, $logger);

        $this->assertNull($loader->loadSubstance('Ethanol'));
    }

    public function testLoadSubstanceHandlesNetworkException(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new TransportException('Connection timeout');
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('CompTox API request failed'));

        $loader = new CompToxSubstanceLoader($httpClient, $logger);

        $this->assertNull($loader->loadSubstance('64-17-5'));
    }

    public function testLoadSubstanceHandlesInvalidJson(): void
    {
        $response = new MockResponse('<!DOCTYPE html><html><body>Error</body></html>', [
            'headers' => ['Content-Type' => 'text/html'],
        ]);

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $this->assertNull($loader->loadSubstance('64-17-5'));
    }

    public function testLoadSubstanceHandlesMissingOptionalFields(): void
    {
        $response = new MockResponse(json_encode([
            [
                'dtxsid' => 'DTXSID7020182',
                'preferredName' => 'Ethanol',
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Ethanol');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertNull($substance->getCASNumber());
        $this->assertNull($substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceSelectsExactMatchAmongMultipleResults(): void
    {
        $response = new MockResponse(json_encode([
            [
                'dtxsid' => 'DTXSID1234567',
                'preferredName' => 'Ethanolamine',
                'casrn' => '141-43-5',
                'molFormula' => 'C2H7NO',
            ],
            [
                'dtxsid' => 'DTXSID7020182',
                'preferredName' => 'Ethanol',
                'casrn' => '64-17-5',
                'molFormula' => 'C2H6O',
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Ethanol');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceHandlesWrappedDataResponse(): void
    {
        $response = new MockResponse(json_encode([
            'data' => [
                [
                    'dtx_sid' => 'DTXSID7020182',
                    'preferred_name' => 'Ethanol',
                    'cas_rn' => '64-17-5',
                    'mol_formula' => 'C2H6O',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('https://comptox.epa.gov/dashboard/chemical/details/DTXSID7020182', $substance->getSource());
    }

    public function testLoadSubstanceReturnsNullWhenNoChemicalFieldsPresent(): void
    {
        $response = new MockResponse(json_encode([
            [
                'unknown_field' => 'value',
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$response]);
        $loader = new CompToxSubstanceLoader($httpClient, new NullLogger());

        $this->assertNull($loader->loadSubstance('64-17-5'));
    }
}
