<?php

/**
 * Learniq Request Submit Controller
 *
 * Submits a request a guardian or an adult learner makes for a learner, in
 * one server call: a subject choice for a curriculum plan, or a conference
 * signup for a round. The server takes the tenant from the plan or the round
 * and the learner's profile reference from the learner, creates the request
 * as the caller and fires its `submit` transition, whose guard checks that
 * the caller is the learner or a linked guardian. A refused submit leaves no
 * draft behind.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\DraftSubmitException;
use OCA\Learniq\Service\DraftSubmitter;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Create-and-submit endpoints for requests made for a learner.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */
class RequestSubmitController extends Controller {

	private const REGISTER = 'learniq';

	private const STATUS = [
		DraftSubmitException::INVALID => Http::STATUS_UNPROCESSABLE_ENTITY,
		DraftSubmitException::REFUSED => Http::STATUS_FORBIDDEN,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request       The request.
	 * @param IUserSession       $userSession   The signed-in user.
	 * @param ObjectService      $objectService Reads the plan or the round as the caller.
	 * @param LearnerRefResolver $profiles      The learner's profile reference.
	 * @param DraftSubmitter     $submitter     Creates and submits.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolver $profiles,
		private readonly DraftSubmitter $submitter,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Submit a subject choice for a learner and a curriculum plan.
	 *
	 * Body: `learnerId`, `curriculumPlanId`, `academicYear`,
	 * `selectedElectiveCourseIds`, `guardianConsentGiven`.
	 *
	 * @return JSONResponse 201 with the submitted choice, or 401 / 404 / 403 / 422.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	#[NoAdminRequired]
	public function subjectChoice(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$plan = $this->readable(id: $this->param(key: 'curriculumPlanId'), schema: 'curriculum-plan');
		if ($plan === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return $this->submit(
			schema: 'subject-choice',
			data: [
				'learnerId' => $this->param(key: 'learnerId'),
				'curriculumPlanId' => (string)$plan['id'],
				'academicYear' => $this->param(key: 'academicYear'),
				'selectedElectiveCourseIds' => $this->stringList(key: 'selectedElectiveCourseIds'),
				'guardianConsentGiven' => ($this->request->getParam('guardianConsentGiven') === true),
				'tenant_id' => (string)($plan['tenant_id'] ?? ''),
			]
		);
	}//end subjectChoice()

	/**
	 * Submit a conference signup for a learner and a round.
	 *
	 * Body: `conferenceRoundId`, `learnerId`, `requestedTeacherIds`, `notes`.
	 *
	 * @return JSONResponse 201 with the submitted signup, or 401 / 404 / 403 / 422.
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
	 */
	#[NoAdminRequired]
	public function conferenceSignup(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$round = $this->readable(id: $this->param(key: 'conferenceRoundId'), schema: 'conference-round');
		if ($round === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$tenantId = (string)($round['tenant_id'] ?? '');
		$learnerId = $this->param(key: 'learnerId');
		$notes = $this->param(key: 'notes');
		if ($notes === '') {
			$notes = null;
		}

		return $this->submit(
			schema: 'conference-signup',
			data: [
				'conferenceRoundId' => (string)$round['id'],
				'learnerId' => $learnerId,
				'learnerRef' => $this->learnerRef(learnerId: $learnerId, tenantId: $tenantId),
				'requestedTeacherIds' => $this->stringList(key: 'requestedTeacherIds'),
				'notes' => $notes,
				'tenant_id' => $tenantId,
			]
		);
	}//end conferenceSignup()

	/**
	 * Create and submit, answering with the outcome.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The new object.
	 *
	 * @return JSONResponse
	 */
	private function submit(string $schema, array $data): JSONResponse {
		try {
			return new JSONResponse(data: $this->submitter->createAndSubmit(schema: $schema, data: $data), statusCode: Http::STATUS_CREATED);
		} catch (DraftSubmitException $exception) {
			return new JSONResponse(data: ['error' => $exception->getReason()], statusCode: self::STATUS[$exception->getReason()]);
		}
	}//end submit()

	/**
	 * An object the caller may read, as an array, or null.
	 *
	 * @param string $id     The uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readable(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $exception) {
			return null;
		}

		return $object?->jsonSerialize();
	}//end readable()

	/**
	 * The learner's profile reference in the tenant, or anywhere when the
	 * tenant is unknown.
	 *
	 * @param string $learnerId The learner's Nextcloud user id.
	 * @param string $tenantId  The tenant, or ''.
	 *
	 * @return string|null
	 */
	private function learnerRef(string $learnerId, string $tenantId): ?string {
		try {
			if ($tenantId !== '') {
				return $this->profiles->resolveInTenant(learnerId: $learnerId, tenantId: $tenantId);
			}

			return $this->profiles->resolve(learnerId: $learnerId);
		} catch (Throwable $exception) {
			return null;
		}
	}//end learnerRef()

	/**
	 * A string request parameter, or ''.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string
	 */
	private function param(string $key): string {
		$value = $this->request->getParam($key);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end param()

	/**
	 * A list-of-strings request parameter, other entries dropped.
	 *
	 * @param string $key The parameter.
	 *
	 * @return array<int, string>
	 */
	private function stringList(string $key): array {
		$value = $this->request->getParam($key);
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) === true && $item !== ''));
	}//end stringList()
}//end class
