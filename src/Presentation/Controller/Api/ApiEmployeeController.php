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

namespace App\Presentation\Controller\Api;

use App\Application\Api\CompanyAwareUserInterface;
use App\Application\Api\Employee\CreateEmployeeRequest;
use App\Application\Api\Employee\EmployeeApiResource;
use App\Application\Api\Employee\EmployeeApiService;
use App\Application\Api\Employee\UpdateEmployeeRequest;
use App\Domain\Entity\Employee;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\EmployeeRepository;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/employees', name: 'api_v1_employee_')]
#[IsGranted('ROLE_API')]
#[OA\Tag(name: 'Employees')]
final class ApiEmployeeController extends AbstractController
{
    public function __construct(
        private readonly EmployeeApiService $service,
        private readonly EmployeeRepository $employeeRepository,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(summary: 'List all employees')]
    #[OA\Response(response: 200, description: 'Employee list', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: EmployeeApiResource::class))))]
    public function list(): JsonResponse
    {
        $company = $this->requireCompany();
        $employees = $this->employeeRepository->findAllByCompany($company);

        return $this->json(array_map(
            static fn (Employee $e) => EmployeeApiResource::fromEntity($e),
            $employees,
        ));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(summary: 'Get a single employee')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Employee', content: new OA\JsonContent(ref: new Model(type: EmployeeApiResource::class)))]
    #[OA\Response(response: 404, description: 'Employee not found')]
    public function show(int $id): JsonResponse
    {
        $employee = $this->requireEmployee($id);

        return $this->json(EmployeeApiResource::fromEntity($employee));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(summary: 'Create an employee with a linked user account')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateEmployeeRequest::class)))]
    #[OA\Response(response: 201, description: 'Employee created', content: new OA\JsonContent(ref: new Model(type: EmployeeApiResource::class)))]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function create(#[MapRequestPayload] CreateEmployeeRequest $request): JsonResponse
    {
        $company = $this->requireCompany();
        $employee = $this->service->create($request, $company);

        return $this->json(
            EmployeeApiResource::fromEntity($employee),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(summary: 'Update an employee (partial update)')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateEmployeeRequest::class)))]
    #[OA\Response(response: 200, description: 'Employee updated', content: new OA\JsonContent(ref: new Model(type: EmployeeApiResource::class)))]
    public function update(int $id, #[MapRequestPayload] UpdateEmployeeRequest $request): JsonResponse
    {
        $employee = $this->requireEmployee($id);
        $this->service->update($employee, $request);

        return $this->json(EmployeeApiResource::fromEntity($employee));
    }

    #[Route('/{id}', name: 'deactivate', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(summary: 'Deactivate an employee (sets left_at date)')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'exitDate', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-12-31'))]
    #[OA\Response(response: 204, description: 'Employee deactivated')]
    #[OA\Response(response: 404, description: 'Employee not found')]
    public function deactivate(int $id, Request $request): JsonResponse
    {
        $employee = $this->requireEmployee($id);

        $exitDate = null;
        $rawDate = $request->query->get('exitDate');
        if (null !== $rawDate) {
            $exitDate = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $rawDate) ?: null;
        }

        $this->service->deactivate($employee, $exitDate);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function requireCompany(): \App\Domain\Entity\Company
    {
        $apiUser = $this->getUser();
        if (!$apiUser instanceof CompanyAwareUserInterface) {
            throw new \LogicException('Expected CompanyAwareUserInterface principal.');
        }

        $company = $this->companyRepository->find($apiUser->getCompanyId());
        if (null === $company) {
            throw new \LogicException('Company not found for this API token.');
        }

        return $company;
    }

    private function requireEmployee(int $id): Employee
    {
        $company = $this->requireCompany();
        $employee = $this->employeeRepository->find($id);

        if (null === $employee || $employee->getCompany()->getId() !== $company->getId()) {
            throw new NotFoundHttpException('Employee not found.');
        }

        return $employee;
    }
}
