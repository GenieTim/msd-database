<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Substance;
use App\Service\NistSubstanceLoader;
use App\Service\SubstanceLoaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NistSubstanceLoaderTest extends TestCase
{
    private const string ETHANOL_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<title>Ethanol</title>
<meta name="DCTERMS.title" content="Ethanol" />
<meta name="og:title" content="Ethanol" />
<meta name="og:image:alt" content="C2H6O" />
<script type="application/ld+json">
{
  "@context" : "http://schema.org/",
  "@type" : "MolecularEntity",
  "name" : "Ethanol",
  "molecularFormula" : "C2H6O",
  "molecularWeight" : "46.0684 amu"
}
</script>
</head>
<body>
<main id="main">
<h1 id="Top">Ethanol</h1>
<ul>
<li><strong><a href="http://goldbook.iupac.org/E02063.html">Formula</a>:</strong> C<sub>2</sub>H<sub>6</sub>O</li>
<li><strong>Molecular weight:</strong> 46.0684</li>
<li><strong>CAS Registry Number:</strong> 64-17-5</li>
<li><strong>Other data available:</strong>
<ul>
<li><a href="/cgi/cbook.cgi?ID=C64175&amp;Units=SI&amp;Mask=1#Thermo-Gas">Gas phase thermochemistry data</a></li>
</ul></li>
</ul>
</main>
</body>
</html>
HTML;

    private const string METHANE_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<title>Methane - NIST Chemistry WebBook</title>
</head>
<body>
<main id="main">
<h1 id="Top">Methane</h1>
<ul>
<li><strong>Formula:</strong> CH<sub>4</sub></li>
<li><strong>Molecular weight:</strong> 16.0425</li>
<li><strong>CAS Registry Number:</strong> 74-82-8</li>
<li><a href="/cgi/cbook.cgi?ID=C74828&amp;Units=SI">Permanent link</a></li>
</ul>
</main>
</body>
</html>
HTML;

    private const string SEARCH_RESULTS_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<title>Search Results</title>
</head>
<body>
<main id="main">
<h1>Search Results</h1>
<p>2 matching species were found.</p>
<ol>
<li><a href="/cgi/cbook.cgi?ID=C64175&amp;Units=SI">Ethanol</a>  (C<sub>2</sub>H<sub>6</sub>O)</li>
<li><a href="/cgi/cbook.cgi?ID=C115106&amp;Units=SI">Dimethyl ether</a>  (C<sub>2</sub>H<sub>6</sub>O)</li>
</ol>
</main>
</body>
</html>
HTML;

    private const string NOT_FOUND_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<title>Name Not Found</title>
</head>
<body>
<main id="main">
<h1>Name Not Found</h1>
<p>The requested name (nonexistentxyz) was not found in the database.</p>
</main>
</body>
</html>
HTML;

    private const string EMPTY_RESULTS_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<title>Search Results</title>
</head>
<body>
<main id="main">
<h1>Search Results</h1>
<p>0 matching species were found.</p>
</main>
</body>
</html>
HTML;

    public function testImplementsSubstanceLoaderInterface(): void
    {
        $loader = new NistSubstanceLoader(new MockHttpClient(), new NullLogger());
        $this->assertInstanceOf(SubstanceLoaderInterface::class, $loader);
    }

    public function testSupports(): void
    {
        $loader = new NistSubstanceLoader(new MockHttpClient(), new NullLogger());
        $this->assertTrue($loader->supports('64-17-5'));
        $this->assertTrue($loader->supports('Ethanol'));
        $this->assertTrue($loader->supports('C2H6O'));
        $this->assertFalse($loader->supports(''));
        $this->assertFalse($loader->supports('   '));
    }

    public function testLoadSubstanceByCasNumber(): void
    {
        $mockResponse = new MockResponse(self::ETHANOL_HTML, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'text/html'],
        ]);

        $httpClient = new MockHttpClient([$mockResponse]);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('64-17-5', $substance->getCASNumber());
        $this->assertSame('https://webbook.nist.gov/cgi/cbook.cgi?ID=C64175&Units=SI', $substance->getSource());
    }

    public function testLoadSubstanceByExplicitIdPrefix(): void
    {
        $mockResponse = new MockResponse(self::ETHANOL_HTML, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'text/html'],
        ]);

        $httpClient = new MockHttpClient([$mockResponse]);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('ID=64-17-5');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('64-17-5', $substance->getCASNumber());
    }

    public function testLoadSubstanceByNistId(): void
    {
        $mockResponse = new MockResponse(self::METHANE_HTML, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'text/html'],
        ]);

        $httpClient = new MockHttpClient([$mockResponse]);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('C74828');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Methane', $substance->getName());
        $this->assertSame('CH4', $substance->getFormula());
        $this->assertSame('74-82-8', $substance->getCASNumber());
        $this->assertSame('https://webbook.nist.gov/cgi/cbook.cgi?ID=C74828&Units=SI', $substance->getSource());
    }

    public function testLoadSubstanceByName(): void
    {
        $mockResponse = new MockResponse(self::ETHANOL_HTML, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'text/html'],
        ]);

        $httpClient = new MockHttpClient([$mockResponse]);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('ethanol');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('64-17-5', $substance->getCASNumber());
    }

    public function testLoadSubstanceByExplicitNamePrefix(): void
    {
        $mockResponse = new MockResponse(self::ETHANOL_HTML, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'text/html'],
        ]);

        $httpClient = new MockHttpClient([$mockResponse]);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Name=ethanol');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
    }

    public function testLoadSubstanceByFormulaWithDisambiguationSearchResults(): void
    {
        // 1st request: Formula search returns multi-result search results page
        // 2nd request: Loader follows the Ethanol link to fetch the compound page
        $responses = [
            new MockResponse(self::SEARCH_RESULTS_HTML, [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/html'],
            ]),
            new MockResponse(self::ETHANOL_HTML, [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/html'],
            ]),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Formula=C2H6O');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('64-17-5', $substance->getCASNumber());
    }

    public function testLoadSubstanceFormulaFallbackWhenNameNotFound(): void
    {
        // 1st request: Name=C2H6O returns Not Found
        // 2nd request: Formula=C2H6O returns Search Results list
        // 3rd request: Follows link to Ethanol compound page
        $responses = [
            new MockResponse(self::NOT_FOUND_HTML, [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/html'],
            ]),
            new MockResponse(self::SEARCH_RESULTS_HTML, [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/html'],
            ]),
            new MockResponse(self::ETHANOL_HTML, [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/html'],
            ]),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('C2H6O');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Ethanol', $substance->getName());
        $this->assertSame('C2H6O', $substance->getFormula());
        $this->assertSame('64-17-5', $substance->getCASNumber());
    }

    public function testLoadSubstanceWithoutCasNumber(): void
    {
        $htmlWithoutCas = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<title>Hydroxyl radical</title>
</head>
<body>
<main id="main">
<h1 id="Top">Hydroxyl radical</h1>
<ul>
<li><strong>Formula:</strong> HO</li>
</ul>
</main>
</body>
</html>
HTML;

        $mockResponse = new MockResponse($htmlWithoutCas, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'text/html'],
        ]);

        $httpClient = new MockHttpClient([$mockResponse]);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('Hydroxyl radical');

        $this->assertInstanceOf(Substance::class, $substance);
        $this->assertSame('Hydroxyl radical', $substance->getName());
        $this->assertSame('HO', $substance->getFormula());
        $this->assertNull($substance->getCASNumber());
    }

    public function testLoadSubstanceReturnsNullOnNotFound(): void
    {
        $responses = [
            new MockResponse(self::NOT_FOUND_HTML, ['http_code' => 200]),
            new MockResponse(self::EMPTY_RESULTS_HTML, ['http_code' => 200]),
        ];

        $httpClient = new MockHttpClient($responses);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('nonexistentxyz');
        $this->assertNull($substance);
    }

    public function testLoadSubstanceReturnsNullOnHttpError(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 404]);
        $httpClient = new MockHttpClient([$mockResponse]);
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $substance = $loader->loadSubstance('64-17-5');
        $this->assertNull($substance);
    }

    public function testLoadSubstanceReturnsNullOnEmptySearch(): void
    {
        $httpClient = new MockHttpClient();
        $loader = new NistSubstanceLoader($httpClient, new NullLogger());

        $this->assertNull($loader->loadSubstance(''));
        $this->assertNull($loader->loadSubstance('   '));
    }
}
