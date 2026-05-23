<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Api\ApiTokenManager;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Company;
use App\Domain\Repository\ApiTokenRepository;
use App\Domain\Repository\CompanyRepository;
use App\Presentation\Form\ApiTokenFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/api-tokens', name: 'app_admin_api_token_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminApiTokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenRepository $repository,
        private readonly CompanyRepository $companyRepository,
        private readonly ApiTokenManager $tokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->requireCompany();

        return $this->render('admin/api_tokens/index.html.twig', [
            'tokens' => $this->repository->findAllByCompany($company),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(ApiTokenFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $name */
            $name = $form->get('name')->getData();
            /** @var \DateTimeImmutable|null $expiresAt */
            $expiresAt = $form->get('expiresAt')->getData();

            $result = $this->tokenManager->create($company, $name, $expiresAt);

            // Render directly — no PRG redirect, so the raw token never touches
            // the session store (disk/DB). Cache-Control: no-store prevents caching.
            $response = $this->render('admin/api_tokens/show_generated.html.twig', [
                'raw_token' => $result['rawToken'],
            ]);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        return $this->render('admin/api_tokens/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Legacy redirect — token is now rendered directly in the POST response.
     * Kept so that any bookmarked /generated URL doesn't 404.
     */
    #[Route('/generated', name: 'show_generated', methods: ['GET'])]
    public function showGenerated(): Response
    {
        return $this->redirectToRoute('app_admin_api_token_index');
    }

    #[Route('/{id}/revoke', name: 'revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(Request $request, ApiToken $apiToken): Response
    {
        $csrfToken = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('revoke-api-token-'.$apiToken->getId(), $csrfToken)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->tokenManager->revoke($apiToken);
        $this->addFlash('success', $this->translator->trans('admin.api_tokens.flash.revoked'));

        return $this->redirectToRoute('app_admin_api_token_index');
    }

    private function requireCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }
}
