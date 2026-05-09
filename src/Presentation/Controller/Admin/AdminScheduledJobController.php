<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Scheduler\ScheduledJobConfigManagerInterface;
use App\Application\Scheduler\ScheduledJobRegistry;
use App\Domain\Repository\ScheduledJobConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin surface for the scheduled-job toggle layer (#35 phase 3).
 *
 * Lists every job from the registry alongside its DB run-state row (if
 * any). Each row carries a toggle form so admins can flip jobs on/off
 * without ssh'ing the box. "Last run" + status badge surface what the
 * cron actually did so silent failures don't go unnoticed.
 *
 * Read-only on the run history — failure detail comes from the row's
 * lastError column. No "force-run" button shipped here; if needed it
 * lands as a phase 3.5.
 */
#[Route('/admin/scheduled-jobs', name: 'app_admin_scheduled_job_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminScheduledJobController extends AbstractController
{
    public function __construct(
        private readonly ScheduledJobRegistry $registry,
        private readonly ScheduledJobConfigRepository $repository,
        private readonly ScheduledJobConfigManagerInterface $manager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // Merge static metadata with the DB run state. A job without a
        // row yet shows "never ran" + enabled-by-default — handlers
        // auto-provision rows on first contact (phase 2).
        $jobs = [];
        foreach ($this->registry->all() as $metadata) {
            $config = $this->repository->findOneByName($metadata->name);
            $jobs[] = [
                'metadata' => $metadata,
                'config' => $config,
            ];
        }

        return $this->render('admin/scheduled_jobs/index.html.twig', [
            'jobs' => $jobs,
        ]);
    }

    #[Route('/{name}/toggle', name: 'toggle', methods: ['POST'], requirements: ['name' => '[a-z0-9-]+'])]
    public function toggle(Request $request, string $name): Response
    {
        $known = array_filter(
            $this->registry->all(),
            static fn ($metadata): bool => $metadata->name === $name,
        );
        if ([] === $known) {
            // Unknown name = either typo or a job that was retired —
            // 404 either way.
            throw $this->createNotFoundException();
        }

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('scheduled-job-toggle-'.$name, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $current = $this->repository->findOneByName($name);
        // Default-true semantics: a missing row means "implicitly enabled",
        // so the toggle action flips it to disabled (and provisions the row).
        $newState = null === $current ? false : !$current->isEnabled();
        $this->manager->setEnabled($name, $newState);

        $this->addFlash('success', $this->translator->trans(
            $newState ? 'admin.scheduled_jobs.flash.enabled' : 'admin.scheduled_jobs.flash.disabled',
            ['%name%' => $name],
        ));

        return $this->redirectToRoute('app_admin_scheduled_job_index');
    }
}
