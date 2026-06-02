<?php

declare(strict_types=1);

namespace App\Controller;

use App\WhatsApp\SessionStatus;
use App\WhatsApp\WhatsAppClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The open, host-bound pairing page (ADR 0002). Renders the shell with an
 * initial session status; the inline client JS then drives the flow against the
 * `/session*` routes. Any channel credentials live only in {@see WhatsAppClient}
 * and never reach this template.
 */
final class IndexController extends AbstractController
{
    public function __construct(private readonly WhatsAppClient $engine)
    {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        try {
            $initialStatus = $this->engine->getSessionStatus();
        } catch (\Throwable) {
            $initialStatus = SessionStatus::UNKNOWN;
        }

        return $this->render('pairing.html.twig', ['initialStatus' => $initialStatus]);
    }
}
