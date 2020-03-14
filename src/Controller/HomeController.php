<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class HomeController extends AbstractController
{
    /**
     * @Route("/home", name="home")
     * @Route("/", name="index")
     */
    public function index()
    {
        return $this->render('home/index.html.twig', array());
    }
}
