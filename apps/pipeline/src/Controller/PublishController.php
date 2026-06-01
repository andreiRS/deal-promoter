<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FoundDeal;
use DealPromoter\Shared\Channel\ChannelPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles the per-row Publish action (P9).
 *
 * POST /publish/{id} calls the ChannelPublisher seam, marks publish intent on
 * the FoundDeal row, and redirects back to the review page (POST-redirect-GET).
 *
 * The controller depends on the ChannelPublisher interface only; swapping in a
 * real implementation (e.g. WahaChannelPublisher) requires no change here.
 */
final class PublishController extends AbstractController
{
    public function __construct(private readonly ChannelPublisher $publisher)
    {
    }

    #[Route('/publish/{id}', name: 'publish_deal', methods: ['POST'])]
    public function publish(int $id, EntityManagerInterface $em): Response
    {
        $deal = $em->find(FoundDeal::class, $id);

        if (!$deal instanceof FoundDeal) {
            throw $this->createNotFoundException(\sprintf('FoundDeal #%d not found.', $id));
        }

        $this->publisher->publish($deal);
        $deal->markPublishRequested(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', \sprintf('Publish requested for %s', $deal->getAsin()));

        return $this->redirectToRoute('review');
    }
}
