<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Substance;
use App\Service\SubstanceLoaderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
}

