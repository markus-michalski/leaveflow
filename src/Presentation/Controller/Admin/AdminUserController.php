<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Security\UserProvisioningService;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\ResetPasswordRequestRepository;
use App\Domain\Repository\UserRepository;
use App\Presentation\Form\AdminUserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/admin/users', name: 'app_admin_user_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly UserProvisioningService $userProvisioning,
    ) {
    }

    private const int PER_PAGE = 25;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statusParam = $request->query->get('status');
        $activeFilter = match ($statusParam) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        $rawQuery = trim((string) $request->query->get('q', ''));
        $query = '' === $rawQuery ? null : $rawQuery;

        $page = max(1, (int) $request->query->get('page', 1));

        $total = $this->userRepository->countSearch($activeFilter, $query);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        // Clamp out-of-range page numbers — friendlier than a 404 when the
        // user lands on ?page=99 after deactivating a bunch of accounts.
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $users = $this->userRepository->searchPaginated($activeFilter, $query, $page, self::PER_PAGE);

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => self::PER_PAGE,
            'status' => $statusParam,
            'q' => $rawQuery,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured — run fixtures or create one via setup.');
        }

        $user = new User($company, 'placeholder@local', UserRole::Employee);

        $form = $this->createForm(AdminUserType::class, $user, [
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            $user = $this->userProvisioning->provisionLocal($company, $email, $user->getRole());
            /** @var string|null $locale */
            $locale = $form->get('locale')->getData();
            $user->setLocale($locale);
            $this->entityManager->flush();

            $this->sendInvitationEmail($user);

            $this->addFlash(
                'success',
                $this->translator->trans('admin.users.flash.created', ['%email%' => $user->getEmail()])
            );

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, User $user): Response
    {
        $form = $this->createForm(AdminUserType::class, $user, [
            'is_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('admin.users.flash.updated', ['%email%' => $user->getEmail()])
            );

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/toggle-active', name: 'toggle_active', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleActive(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('toggle-active-'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->isActive()) {
            $user->deactivate();
            $flashKey = 'admin.users.flash.deactivated';
        } else {
            $user->activate();
            $flashKey = 'admin.users.flash.activated';
        }

        $this->entityManager->flush();

        $this->addFlash(
            'success',
            $this->translator->trans($flashKey, ['%email%' => $user->getEmail()])
        );

        return $this->redirectToRoute('app_admin_user_index');
    }

    #[Route('/{id}/2fa-reset', name: 'reset_2fa', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reset2fa(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('reset-2fa-'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Lockout recovery only — there's no other admin reason to nuke
        // 2FA for a user. The user has to enable it again on next login;
        // the company-pflicht banner pushes them there automatically.
        $user->disableTotp();
        $this->entityManager->flush();

        $this->addFlash(
            'success',
            $this->translator->trans('admin.users.flash.2fa_reset', ['%email%' => $user->getEmail()])
        );

        return $this->redirectToRoute('app_admin_user_index');
    }

    #[Route('/{id}/send-reset', name: 'send_reset', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sendReset(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('send-reset-'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->sendInvitationEmail($user);

        $this->addFlash(
            'success',
            $this->translator->trans('admin.users.flash.reset_sent', ['%email%' => $user->getEmail()])
        );

        return $this->redirectToRoute('app_admin_user_index');
    }

    private function sendInvitationEmail(User $user): void
    {
        // SSO users authenticate via their IdP — no local password to set.
        if (!$user->getAuthSource()->isLocal()) {
            return;
        }

        // Admin-triggered invitations must override any pending reset request
        // so the admin can re-send without waiting out the bundle throttle.
        $this->resetPasswordRequestRepository->removeRequests($user);

        $resetToken = $this->resetPasswordHelper->generateResetToken($user);

        $email = (new \Symfony\Bridge\Twig\Mime\TemplatedEmail())
            ->from(new Address('no-reply@leaveflow.test', 'LeaveFlow'))
            ->to((string) $user->getEmail())
            ->subject($this->translator->trans('admin.users.invitation.subject'))
            ->htmlTemplate('admin/users/invitation_email.html.twig')
            ->context([
                'resetToken' => $resetToken,
                'user' => $user,
            ]);

        $this->mailer->send($email);
    }
}
