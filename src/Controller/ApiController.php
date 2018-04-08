<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use JMS\SerializerBundle\Serializer;
use App\Service\SubstanceLoaderInterface;
use App\Entity\Substance;

class ApiController extends Controller {

    /**
     * @Route("/api/{format}/substance", name="api_substance")
     */
    public function getSubstanceAction(Request $request, $format, SubstanceLoaderInterface $substanceLoader, $search = FALSE) {
        $name = 'search';
        if (!$search) {
            $search = $request->query->has($name) ? $request->query->get($name) : $request->request->get($name);
        }
        if (!$search) {
            throw $this->createAccessDeniedException('no search parameter given');
        }

        $data = $substanceLoader->loadSubstance($search);

        if (!($data instanceof Substance)) {
            throw $this->createNotFoundException('no substance found for ' . $search);
        }

        return $this->render('api/substance.twig', array(
                    'format' => $format,
                    'data' => $data
        ));
    }

}
