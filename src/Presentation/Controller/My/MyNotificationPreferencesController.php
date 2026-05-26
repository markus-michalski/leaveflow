<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace App\Presentation\Controller\My;

use App\Domain\Entity\NotificationPreference;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-user notification preferences UI — toggles the email channel for each
 * NotificationType. The in-app channel is always on (the inbox is not
 * opt-out-able).
 *
 * Storage strategy is lazy: no row = email enabled (default). A row appears
 * only when the user explicitly opts out, or once they edit preferences and
 * the form serialization writes both states. The repository's
 * isEmailEnabledFor() encodes that default for the dispatch path.
 */
#[IsGranted('ROLE_USER')]
final class MyNotificationPreferencesController extends AbstractController
{
    public function __construct(
        private readonly NotificationPreferenceRepository $preferences,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/my/notifications/preferences', name: 'app_my_notification_preferences', methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->resolveUser();

        return $this->render('my/notifications/preferences.html.twig', [
            'preferencesByType' => $this->preferences->findAllForUserKeyedByType($user),
            'types' => $this->visibleTypes(),
        ]);
    }

    #[Route('/my/notifications/preferences', name: 'app_my_notification_preferences_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('notifications-preferences', $token)) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->resolveUser();
        $existing = $this->preferences->findAllForUserKeyedByType($user);

        // Form posts as `email[<type.value>] = "1"` for each checked box.
        // Unchecked boxes don't post — absence means opted-out.
        /** @var array<string, mixed> $submitted */
        $submitted = (array) $request->request->all('email');

        // Only iterate types the user is actually allowed to manage. A
        // POST that smuggles a type outside that set (e.g. a manager
        // tampering with EscalationTriggered) gets silently ignored —
        // the omitted type just keeps whatever state it had.
        foreach ($this->visibleTypes() as $type) {
            $wantEnabled = isset($submitted[$type->value]) && '1' === $submitted[$type->value];
            $pref = $existing[$type->value] ?? null;

            $this->applyToggle($user, $type, $pref, $wantEnabled);
        }

        $this->entityManager->flush();
        $this->addFlash(
            'success',
            $this->translator->trans('notifications.preferences.saved', [], 'notifications'),
        );

        return $this->redirectToRoute('app_my_notification_preferences');
    }

    /**
     * @return list<NotificationType>
     */
    private function visibleTypes(): array
    {
        $visible = [];
        foreach (NotificationType::cases() as $type) {
            if ($this->authorizationChecker->isGranted($type->requiredSymfonyRole())) {
                $visible[] = $type;
            }
        }

        return $visible;
    }

    private function applyToggle(
        User $user,
        NotificationType $type,
        ?NotificationPreference $existing,
        bool $wantEnabled,
    ): void {
        if ($wantEnabled) {
            // Default state is enabled; only update if a row already exists
            // and currently says disabled.
            if (null !== $existing && !$existing->isEmailEnabled()) {
                $existing->enableEmail();
            }

            return;
        }

        // User wants disabled — must persist a row to override the default.
        if (null === $existing) {
            $pref = new NotificationPreference($user, $type);
            $pref->disableEmail();
            $this->entityManager->persist($pref);

            return;
        }

        if ($existing->isEmailEnabled()) {
            $existing->disableEmail();
        }
    }

    private function resolveUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
