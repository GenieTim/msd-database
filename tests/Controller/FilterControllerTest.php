<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FilterControllerTest extends WebTestCase
{
    public function testFilterPageRendersForm(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/filter');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('form')->count());
    }
}
