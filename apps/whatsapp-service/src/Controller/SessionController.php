<?php

declare(strict_types=1);

namespace App\Controller;

use App\Waha\SessionStatus;
use App\Waha\WahaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Open, host-bound pairing surface (ADR 0002 keeps these ungated; only the
 * future machine /send is gated). Ports the prototype's `/api/session*` routes.
 * Every WAHA call goes through {@see WahaClient}, which alone holds the API key.
 */
final class SessionController extends AbstractController
{
    public function __construct(private readonly WahaClient $waha)
    {
    }

    #[Route('/session', name: 'session_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        try {
            return new JsonResponse(['status' => $this->waha->getSessionStatus()]);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['status' => SessionStatus::UNKNOWN, 'error' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }

    #[Route('/session/start', name: 'session_start', methods: ['POST'])]
    public function start(): JsonResponse
    {
        try {
            $this->waha->startSession();

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['ok' => false, 'error' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }

    #[Route('/session/logout', name: 'session_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        // Fire-and-forget, mirroring the prototype: the operator just wants to
        // drop back to the Connect state.
        $this->waha->logoutSession();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/session/qr', name: 'session_qr', methods: ['GET'])]
    public function qr(): Response
    {
        $qr = $this->waha->getQrImage();

        if (!$qr->ok) {
            return new Response('QR not available', $qr->status);
        }

        return new Response($qr->body, Response::HTTP_OK, [
            'Content-Type' => $qr->contentType,
            'Cache-Control' => 'no-store',
        ]);
    }
}
