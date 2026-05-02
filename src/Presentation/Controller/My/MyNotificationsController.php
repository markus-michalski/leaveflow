<?php

declare(strict_types=1);

namespace App\Presentation\Controller\My;

use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service notification inbox + bell.
 *
 * The bell route returns just the lightweight badge fragment that the
 * Turbo Frame in the global app shell polls every 30 seconds. The full
 * inbox lists recent notifications (read + unread) and offers per-row
 * mark-as-read + a bulk mark-all action.
 *
 * Per-type rendering is delegated to `_item_<type>.html.twig` partials —
 * the inbox switches on the notification type and renders the matching
 * fragment with the JSON payload.
 */
#[Route('/my/notifications', name: 'app_my_notification_')]
#[IsGranted('ROLE_USER')]
final class MyNotificationsController extends AbstractController
{
    private const int INBOX_LIMIT = 50;

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/bell', name: 'bell', methods: ['GET'])]
    public function bell(): Response
    {
        $user = $this->resolveUser();

        return $this->render('my/notifications/_bell.html.twig', [
            'unreadCount' => $this->notificationRepository->countUnreadForUser($user),
        ]);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->resolveUser();

        return $this->render('my/notifications/index.html.twig', [
            'notifications' => $this->notificationRepository->findRecentForUser($user, self::INBOX_LIMIT),
            'unreadCount' => $this->notificationRepository->countUnreadForUser($user),
        ]);
    }

    #[Route('/{id}/read', name: 'read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(int $id, Request $request): Response
    {
        // Ownership check first so foreign IDs return 404 without leaking
        // CSRF requirements — matches the existing pattern in this codebase.
        $notification = $this->loadOwnedNotification($id);

        $this->assertCsrf($request, 'notification-read-'.$id);

        $notification->markAsRead($this->clock->now());
        $this->entityManager->flush();

        // If the notification deeplinks to a related entity, jump straight
        // there — the user's intent on clicking is to see the source.
        $deeplink = $this->resolveDeeplink($notification);
        if (null !== $deeplink) {
            return $this->redirect($deeplink);
        }

        return $this->redirectToRoute('app_my_notification_index');
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        $this->assertCsrf($request, 'notifications-read-all');

        $user = $this->resolveUser();
        $this->notificationRepository->markAllAsReadForUser($user, $this->clock->now());

        return $this->redirectToRoute('app_my_notification_index');
    }

    private function resolveUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function loadOwnedNotification(int $id): Notification
    {
        $notification = $this->notificationRepository->find($id);
        if (null === $notification || $notification->getRecipient() !== $this->resolveUser()) {
            throw new NotFoundHttpException();
        }

        return $notification;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Maps a notification's relatedEntity reference to a target URL. Phase 8
     * only deeplinks LeaveRequest references — additions in later phases
     * extend the match.
     */
    private function resolveDeeplink(Notification $notification): ?string
    {
        $type = $notification->getRelatedEntityType();
        $id = $notification->getRelatedEntityId();
        if (null === $type || null === $id) {
            return null;
        }

        return match ($type) {
            \App\Domain\Entity\LeaveRequest::class => $this->generateUrl('app_my_leave_request_show', ['id' => $id]),
            default => null,
        };
    }
}
