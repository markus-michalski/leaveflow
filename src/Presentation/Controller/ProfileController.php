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

namespace App\Presentation\Controller;

use App\Application\Entitlement\EntitlementBalanceReader;
use App\Domain\Entity\User;
use App\Domain\Repository\EmployeeRepository;
use App\Presentation\Form\ProfileLocaleFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly EntitlementBalanceReader $balanceReader,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $employee = $this->employeeRepository->findOneByUser($user);

        $balance = null;
        $balanceYear = null;
        if (null !== $employee) {
            $asOf = $this->clock->now();
            $balanceYear = (int) $asOf->format('Y');
            $balance = $this->balanceReader->forEmployee($employee, $balanceYear, $asOf);
        }

        // Lazy-generate the iCal token on first profile view so subscription
        // URLs are immediately usable. Only employees with a profile (who
        // actually have absences to surface) see the section in the template.
        $icalToken = null;
        if (null !== $employee) {
            $existed = null !== $user->getIcalToken();
            $icalToken = $user->ensureIcalToken();
            if (!$existed) {
                $this->entityManager->flush();
            }
        }

        $localeForm = $this->createForm(ProfileLocaleFormType::class, ['locale' => $user->getLocale()]);

        return $this->render('profile/show.html.twig', [
            'user' => $user,
            'employee' => $employee,
            'balance' => $balance,
            'balanceYear' => $balanceYear,
            'icalToken' => $icalToken,
            'icalHasTeam' => null !== $employee && null !== $employee->getDepartment(),
            'localeForm' => $localeForm,
        ]);
    }

    #[Route('/profile/locale', name: 'app_profile_locale', methods: ['POST'])]
    public function setLocale(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(ProfileLocaleFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $locale */
            $locale = $form->get('locale')->getData();
            $user->setLocale($locale);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('profile.locale.flash.saved'));
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/ical/reset', name: 'app_profile_ical_reset', methods: ['POST'])]
    public function resetIcalToken(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('profile_ical_reset', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user->resetIcalToken();
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('profile.ical.flash.reset'));

        return $this->redirectToRoute('app_profile');
    }
}
