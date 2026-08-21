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

    #[Route('/substance/{id}/label/tex', name: 'substance_label_tex', requirements: ['id' => '\d+'])]
    public function downloadTexAction(int $id): Response
    {
        $substance = $this->substanceRepo->find($id);
        if (!$substance instanceof Substance) {
            throw $this->createNotFoundException('Substance not found');
        }

        $tex = $this->labelGenerator->generateTex($substance);
        $filename = sprintf('ghs-label-%s.tex', $this->sanitizeFilename($substance->getName() ?? 'substance'));

        $response = new Response($tex);
        $response->headers->set('Content-Type', 'text/x-tex; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/substance/{id}/label/pdf', name: 'substance_label_pdf', requirements: ['id' => '\d+'])]
    public function downloadPdfAction(int $id): Response
    {
        $substance = $this->substanceRepo->find($id);
        if (!$substance instanceof Substance) {
            throw $this->createNotFoundException('Substance not found');
        }

        $pdf = $this->labelGenerator->generatePdf($substance);
        if ($pdf === null) {
            // Fallback: if pdflatex isn't installed in environment, return LaTeX with helpful notice
            return $this->downloadTexAction($id);
        }

        $filename = sprintf('ghs-label-%s.pdf', $this->sanitizeFilename($substance->getName() ?? 'substance'));

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s"', $filename));

        return $response;
    }

    #[Route('/substance/search/label/tex', name: 'substance_search_label_tex')]
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

        $tex = $this->labelGenerator->generateTex($substance);
        $filename = sprintf('ghs-label-%s.tex', $this->sanitizeFilename($substance->getName() ?? 'substance'));

        $response = new Response($tex);
        $response->headers->set('Content-Type', 'text/x-tex; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/substance/search/label/pdf', name: 'substance_search_label_pdf')]
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

        $pdf = $this->labelGenerator->generatePdf($substance);
        if ($pdf === null) {
            return $this->searchTexAction($request);
        }

        $filename = sprintf('ghs-label-%s.pdf', $this->sanitizeFilename($substance->getName() ?? 'substance'));

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s"', $filename));

        return $response;
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($name))) ?: 'substance';
    }
}
