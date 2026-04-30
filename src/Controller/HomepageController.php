<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomepageController extends AbstractController
{
    #[Route('/', name: 'homepage')]
    public function index(Request $request): Response
    {
        $locale = $request->query->get('locale', 'fr');
        if (!in_array($locale, ['fr', 'en'], true)) {
            $locale = 'fr';
        }
        $request->setLocale($locale);

        return $this->render('homepage/index.html.twig', [
            'locale' => $locale,
        ]);
    }
}
