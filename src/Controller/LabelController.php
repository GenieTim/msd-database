<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Controller;

use App\Entity\Substance;
use App\Repository\SubstanceRepository;
use App\Service\GhsLabelGenerator;
use App\Service\SubstanceLoaderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LabelController extends AbstractController
{
    public function __construct(
        private readonly GhsLabelGenerator $labelGenerator,
        private readonly SubstanceRepository $substanceRepo,
        private readonly SubstanceLoaderInterface $substanceLoader
    ) {
    }

    #[Route('/substance/{id}/label/tex', name: 'substance_label_tex', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadTexAction(int $id): Response
    {
        $substance = $this->substanceRepo->find($id);
        if (!$substance instanceof Substance) {
            throw $this->createNotFoundException('Substance not found');
        }

        return $this->createTexResponse($substance);
    }

    #[Route('/substance/{id}/label/pdf', name: 'substance_label_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadPdfAction(int $id): Response
    {
        $substance = $this->substanceRepo->find($id);
        if (!$substance instanceof Substance) {
            throw $this->createNotFoundException('Substance not found');
        }

        return $this->createPdfResponse($substance);
    }

    #[Route('/substance/search/label/tex', name: 'substance_search_label_tex', methods: ['GET'])]
    public function searchTexAction(Request $request): Response
    {
        $search = (string) $request->query->get('search', '');
        if ($search === '') {
            throw $this->createNotFoundException('No search query provided');
        }

        $substance = $this->substanceLoader->loadSubstance($search);
        if (!$substance instanceof Substance) {
            throw $this->createNotFoundException(sprintf('No substance found for "%s"', $search));
        }

        return $this->createTexResponse($substance);
    }

    #[Route('/substance/search/label/pdf', name: 'substance_search_label_pdf', methods: ['GET'])]
    public function searchPdfAction(Request $request): Response
    {
        $search = (string) $request->query->get('search', '');
        if ($search === '') {
            throw $this->createNotFoundException('No search query provided');
        }

        $substance = $this->substanceLoader->loadSubstance($search);
        if (!$substance instanceof Substance) {
            throw $this->createNotFoundException(sprintf('No substance found for "%s"', $search));
        }

        return $this->createPdfResponse($substance);
    }

    private function createTexResponse(Substance $substance): Response
    {
        $tex = $this->labelGenerator->generateTex($substance);
        $filename = sprintf('ghs-label-%s.tex', $this->sanitizeFilename($substance->getName() ?? 'substance'));

        $response = new Response($tex, Response::HTTP_OK, [
            'Content-Type' => 'text/x-tex; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    private function createPdfResponse(Substance $substance): Response
    {
        $pdf = $this->labelGenerator->generatePdf($substance);
        if ($pdf === null) {
            // Fallback: if pdflatex isn't installed in environment, return LaTeX
            return $this->createTexResponse($substance);
        }

        $filename = sprintf('ghs-label-%s.pdf', $this->sanitizeFilename($substance->getName() ?? 'substance'));

        $response = new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($name))) ?: 'substance';
    }
}
