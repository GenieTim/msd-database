<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Substance;
use App\Service\SubstanceLoaderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ApiController extends AbstractController
{
    #[Route('/api/{format}/substance', name: 'api_substance')]
    public function getSubstanceAction(
        Request $request,
        string $format,
        SubstanceLoaderInterface $substanceLoader,
        ?string $search = null
    ): Response {
        $searchTerm = $search
            ?? $request->query->get('search')
            ?? $request->request->get('search')
            ?? $request->attributes->get('search');

        if (!$searchTerm) {
            throw $this->createNotFoundException('no search parameter given');
        }

        $data = $substanceLoader->loadSubstance((string) $searchTerm);

        if (!($data instanceof Substance)) {
            throw $this->createNotFoundException('no substance found for ' . $searchTerm);
        }

        return $this->render('api/substance.twig', [
            'format' => $format,
            'data' => $data,
        ]);
    }

    #[Route('/api/spec.json', name: 'api_openapi_spec', methods: ['GET'])]
    public function getOpenApiSpecAction(): JsonResponse
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'MSD Database Chemical Substance API',
                'description' => 'REST API for querying chemical substance material safety data (MSDS), GHS hazard classifications, and physical constants from multiple global sources (PubChem, ECHA, ChEBI, EPA CompTox, NIST, GESTIS, Wikidata, Sigma-Aldrich).',
                'version' => '1.0.0',
            ],
            'paths' => [
                '/api/{format}/substance' => [
                    'get' => [
                        'summary' => 'Fetch substance material safety data',
                        'description' => 'Loads or fetches substance data by name, CAS number, CID, or formula in JSON or XML format.',
                        'parameters' => [
                            [
                                'name' => 'format',
                                'in' => 'path',
                                'required' => true,
                                'description' => 'Response serialization format',
                                'schema' => [
                                    'type' => 'string',
                                    'enum' => ['json', 'xml'],
                                    'default' => 'json',
                                ],
                            ],
                            [
                                'name' => 'search',
                                'in' => 'query',
                                'required' => true,
                                'description' => 'Substance search term (chemical name, CAS number, formula, or CID)',
                                'schema' => [
                                    'type' => 'string',
                                    'example' => 'ethanol',
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Substance entity with GHS symbols, statements, and properties',
                            ],
                            '404' => [
                                'description' => 'Substance not found or no search query provided',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return new JsonResponse($spec);
    }

    #[Route('/api/doc', name: 'api_doc', methods: ['GET'])]
    public function getDocAction(): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MSD Database API Documentation</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js"></script>
    <script>
        window.onload = () => {
            window.ui = SwaggerUIBundle({
                url: '/api/spec.json',
                dom_id: '#swagger-ui',
            });
        };
    </script>
</body>
</html>
HTML;

        return new Response($html);
    }
}
