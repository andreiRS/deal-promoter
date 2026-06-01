<?php

declare(strict_types=1);

namespace App\Controller;

use App\Waha\SessionStatus;
use App\Waha\WahaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The open, host-bound pairing page (ADR 0002). Renders the shell with an
 * initial session status; the inline client JS then drives the flow against the
 * `/session*` routes. The WAHA `X-Api-Key` lives only in {@see WahaClient} and
 * never reaches this template.
 */
final class IndexController extends AbstractController
{
    public function __construct(private readonly WahaClient $waha)
    {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        try {
            $initialStatus = $this->waha->getSessionStatus();
        } catch (\Throwable) {
            $initialStatus = SessionStatus::UNKNOWN;
        }

        return $this->render('pairing.html.twig', ['initialStatus' => $initialStatus]);
    }
}
