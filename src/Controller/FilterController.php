<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;
use App\Service\SubstanceLoaderInterface;
use App\Form\SimpleSearchType;
use App\Repository\SubstanceRepository;

class FilterController extends Controller {

    /**
     * @Route("/filter", name="filter")
     */
    public function index(Request $request, SubstanceLoaderInterface $substanceLoader, LoggerInterface $logger) {
        $form = $this->createForm(SimpleSearchType::class);
        $form->handleRequest($request);

        $data = false;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
            $data = $substanceLoader->loadSubstance($form->get('search')->getData());
            } catch (\Exception $e) {
            $logger->warning("Error while loading substance", array('exception' => $e));
            }
        }

        return $this->render('filter/index.html.twig', array(
                    'substance' => $data,
                    'form' => $form->createView()
        ));
    }

}
