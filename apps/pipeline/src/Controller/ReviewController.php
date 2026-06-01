<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CycleRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only review page (P8): renders the latest Cycle's found deals as a table.
 *
 * GET only; it performs no writes and never triggers a Cycle.
 */
final class ReviewController extends AbstractController
{
    #[Route('/', name: 'review', methods: ['GET'])]
    public function index(CycleRunRepository $cycleRuns): Response
    {
        $cycle = $cycleRuns->findLatest();

        return $this->render('review/index.html.twig', [
            'cycle' => $cycle,
        ]);
    }
}
