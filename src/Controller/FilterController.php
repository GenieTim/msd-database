<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\SimpleSearchType;
use App\Service\SubstanceLoaderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FilterController extends AbstractController
{
    #[Route('/filter', name: 'filter')]
    public function index(
        Request $request,
        SubstanceLoaderInterface $substanceLoader,
        LoggerInterface $logger
    ): Response {
        $form = $this->createForm(SimpleSearchType::class);
        $form->handleRequest($request);

        $data = null;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $searchTerm = (string) $form->get('search')->getData();
                $data = $substanceLoader->loadSubstance($searchTerm);
            } catch (\Throwable $e) {
                $logger->warning('Error while loading substance: ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $this->render('filter/index.html.twig', [
            'substance' => $data,
            'form' => $form->createView(),
        ]);
    }
}

