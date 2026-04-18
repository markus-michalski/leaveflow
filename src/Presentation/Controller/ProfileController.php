<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Entity\User;
use App\Domain\Repository\EmployeeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
    ) {
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('profile/show.html.twig', [
            'user' => $user,
            'employee' => $this->employeeRepository->findOneByUser($user),
        ]);
    }
}
