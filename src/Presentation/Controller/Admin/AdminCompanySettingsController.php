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

namespace App\Presentation\Controller\Admin;

use App\Application\Security\EncryptionServiceInterface;
use App\Application\Validator\ValidLdapFilter;
use App\Domain\Entity\Company;
use App\Domain\Repository\CompanyRepository;
use App\Presentation\Form\CompanyProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/company/settings', name: 'app_admin_company_settings_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCompanySettingsController extends AbstractController
{
    private const string LOGO_UPLOAD_RELATIVE_DIR = 'uploads/company';

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly SluggerInterface $slugger,
        private readonly ValidatorInterface $validator,
        private readonly EncryptionServiceInterface $encryption,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(CompanyProfileFormType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $logoFile */
            $logoFile = $form->get('logo')->getData();
            if ($logoFile instanceof UploadedFile) {
                try {
                    $newPath = $this->storeLogo($logoFile);
                    $this->removeLogo($company->getLogoPath());
                    $company->setLogoPath($newPath);
                } catch (FileException) {
                    $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.logo_upload_failed'));

                    return $this->redirectToRoute('app_admin_company_settings_index');
                }
            }

            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('admin.company_settings.flash.profile_saved'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        return $this->render('admin/company_settings/index.html.twig', [
            'company' => $company,
            'today' => \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0),
            'form' => $form->createView(),
        ]);
    }

    #[Route('/logo', name: 'remove_logo', methods: ['POST'])]
    public function removeLogoAction(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_logo', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $this->removeLogo($company->getLogoPath());
        $company->setLogoPath(null);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.company_settings.flash.logo_removed'));

        return $this->redirectToRoute('app_admin_company_settings_index');
    }

    #[Route('/2fa-requirement', name: 'set_2fa_requirement', methods: ['POST'])]
    public function set2faRequirement(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_2fa', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $enable = '1' === $request->request->get('enable');

        if (!$enable) {
            $company->disableTwoFactorRequirement();
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('admin.company_settings.flash.2fa_disabled'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        $enforcedFromRaw = trim((string) $request->request->get('enforced_from'));
        if ('' === $enforcedFromRaw) {
            $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.2fa_invalid_date'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        try {
            $enforcedFrom = new \DateTimeImmutable($enforcedFromRaw);
        } catch (\Exception) {
            $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.2fa_invalid_date'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            $company->enableTwoFactorRequirement($enforcedFrom, $now);
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.2fa_past_date'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('admin.company_settings.flash.2fa_enabled', [
            '%date%' => $enforcedFrom->format('d.m.Y'),
        ]));

        return $this->redirectToRoute('app_admin_company_settings_index');
    }

    #[Route('/google-oauth', name: 'set_google_oauth', methods: ['POST'])]
    public function setGoogleOAuth(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_google', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $enable = '1' === $request->request->get('enable');

        if ($enable) {
            $company->enableGoogleOAuth();
        } else {
            $company->disableGoogleOAuth();
        }

        $rawDomain = trim((string) $request->request->get('hosted_domain', ''));
        $company->setGoogleOAuthHostedDomain('' === $rawDomain ? null : $rawDomain);

        $this->entityManager->flush();

        $key = $enable
            ? 'admin.company_settings.flash.google_oauth_enabled'
            : 'admin.company_settings.flash.google_oauth_disabled';
        $this->addFlash('success', $this->translator->trans($key));

        return $this->redirectToRoute('app_admin_company_settings_index');
    }

    #[Route('/entra-oauth', name: 'set_entra_oauth', methods: ['POST'])]
    public function setEntraOAuth(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_entra', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $enable = '1' === $request->request->get('enable');

        if ($enable) {
            $company->enableEntraOAuth();
        } else {
            $company->disableEntraOAuth();
        }

        $rawTenantId = trim((string) $request->request->get('tenant_id', ''));
        $company->setEntraOAuthTenantId('' === $rawTenantId ? null : $rawTenantId);

        $this->entityManager->flush();

        $key = $enable
            ? 'admin.company_settings.flash.entra_oauth_enabled'
            : 'admin.company_settings.flash.entra_oauth_disabled';
        $this->addFlash('success', $this->translator->trans($key));

        return $this->redirectToRoute('app_admin_company_settings_index');
    }

    #[Route('/teams', name: 'set_teams', methods: ['POST'])]
    public function setTeams(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_teams', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $enable = '1' === $request->request->get('enable');

        if ($enable) {
            $rawUrl = trim((string) $request->request->get('teams_webhook_url', ''));
            if ('' !== $rawUrl) {
                try {
                    $company->setTeamsWebhookUrl($rawUrl);
                } catch (\InvalidArgumentException) {
                    $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.teams_invalid_url'));

                    return $this->redirectToRoute('app_admin_company_settings_index');
                }
            }
            $company->enableTeams();
        } else {
            $company->disableTeams();
        }

        $this->entityManager->flush();

        $key = $enable
            ? 'admin.company_settings.flash.teams_enabled'
            : 'admin.company_settings.flash.teams_disabled';
        $this->addFlash('success', $this->translator->trans($key));

        return $this->redirectToRoute('app_admin_company_settings_index');
    }

    #[Route('/slack', name: 'set_slack', methods: ['POST'])]
    public function setSlack(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_slack', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $enable = '1' === $request->request->get('enable');

        if ($enable) {
            $rawToken = (string) $request->request->get('slack_bot_token', '');
            $rawSecret = (string) $request->request->get('slack_signing_secret', '');
            $channelId = trim((string) $request->request->get('slack_channel_id', ''));

            if ('' !== $rawToken) {
                $company->setSlackBotToken($this->encryption->encrypt($rawToken));
            }
            if ('' !== $rawSecret) {
                $company->setSlackSigningSecret($this->encryption->encrypt($rawSecret));
            }
            $company->setSlackChannelId('' !== $channelId ? $channelId : null);
            $company->enableSlack();
        } else {
            $company->disableSlack();
        }

        $this->entityManager->flush();

        $key = $enable
            ? 'admin.company_settings.flash.slack_enabled'
            : 'admin.company_settings.flash.slack_disabled';
        $this->addFlash('success', $this->translator->trans($key));

        return $this->redirectToRoute('app_admin_company_settings_index');
    }

    #[Route('/ldap', name: 'set_ldap', methods: ['POST'])]
    public function setLdap(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_ldap', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $enable = '1' === $request->request->get('enable');

        // Read all inputs before touching the entity
        $host = trim((string) $request->request->get('ldap_host', '')) ?: null;
        $port = (int) $request->request->get('ldap_port', 389);
        $encryption = trim((string) $request->request->get('ldap_encryption', 'none')) ?: null;
        $bindDn = trim((string) $request->request->get('ldap_bind_dn', '')) ?: null;
        $rawPassword = (string) $request->request->get('ldap_bind_password', '');
        $baseDn = trim((string) $request->request->get('ldap_base_dn', '')) ?: null;
        $rawFilter = trim((string) $request->request->get('ldap_user_filter', '')) ?: null;
        $groupManagerDn = trim((string) $request->request->get('ldap_group_manager_dn', '')) ?: null;
        $groupAdminDn = trim((string) $request->request->get('ldap_group_admin_dn', '')) ?: null;

        // Validate before mutating; skip when disabling LDAP so a stored invalid filter never blocks the disable action
        if ($enable && null !== $rawFilter) {
            $violations = $this->validator->validate($rawFilter, new ValidLdapFilter());
            if (\count($violations) > 0) {
                foreach ($violations as $violation) {
                    $this->addFlash('error', $this->translator->trans((string) $violation->getMessage()));
                }

                return $this->redirectToRoute('app_admin_company_settings_index');
            }
        }

        if ($enable) {
            $company->enableLdap();
        } else {
            $company->disableLdap();
        }
        $company->setLdapHost($host);
        $company->setLdapPort($port > 0 ? $port : null);
        $company->setLdapEncryption($encryption);
        $company->setLdapBindDn($bindDn);
        if ('' !== $rawPassword) {
            $company->setLdapBindPassword($this->encryption->encrypt($rawPassword));
        }
        $company->setLdapBaseDn($baseDn);
        $company->setLdapUserFilter($rawFilter);
        $company->setLdapGroupManagerDn($groupManagerDn);
        $company->setLdapGroupAdminDn($groupAdminDn);

        $this->entityManager->flush();

        $key = $enable
            ? 'admin.company_settings.flash.ldap_enabled'
            : 'admin.company_settings.flash.ldap_disabled';
        $this->addFlash('success', $this->translator->trans($key));

        return $this->redirectToRoute('app_admin_company_settings_index');
    }

    private function requireCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }

    /**
     * Persists the uploaded logo under public/uploads/company/ with a
     * sluggified-then-randomized filename so multiple uploads don't
     * overwrite each other and the URL is cache-friendly. Returns the
     * path relative to public/ for storage on the Company entity.
     */
    private function storeLogo(UploadedFile $file): string
    {
        $original = pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME);
        $safe = $this->slugger->slug($original)->lower()->toString();
        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $filename = \sprintf('%s-%s.%s', $safe, bin2hex(random_bytes(6)), $extension);

        $absoluteDir = $this->publicDir.'/'.self::LOGO_UPLOAD_RELATIVE_DIR;
        $filesystem = new Filesystem();
        $filesystem->mkdir($absoluteDir, 0o755);

        $file->move($absoluteDir, $filename);

        return self::LOGO_UPLOAD_RELATIVE_DIR.'/'.$filename;
    }

    /**
     * Best-effort delete of a previously-uploaded logo. Silent on
     * missing files so a manual filesystem cleanup doesn't error here.
     */
    private function removeLogo(?string $relativePath): void
    {
        if (null === $relativePath || '' === $relativePath) {
            return;
        }
        $absolute = $this->publicDir.'/'.$relativePath;
        if (is_file($absolute)) {
            (new Filesystem())->remove($absolute);
        }
    }
}
