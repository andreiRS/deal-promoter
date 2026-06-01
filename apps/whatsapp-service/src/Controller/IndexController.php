<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health/landing route for the gateway shell (Slice 1).
 *
 * Proves the app boots and serves HTTP. WhatsApp pairing and send surfaces are
 * added in later slices.
 */
final class IndexController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return new Response('WhatsApp gateway is running.');
    }
}
