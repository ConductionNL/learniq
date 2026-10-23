<?php

/**
 * Learniq Bulk Enrol Controller
 *
 * Enrols the learners of a group in a course in one call, from the header
 * action on the enrolments list. The course and the group are read as the
 * caller, and each enrolment is created as the caller.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Enrolment\BulkEnrolService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * POST /api/enrolments/bulk.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
 */
class BulkEnrolController extends Controller {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request       The request.
	 * @param IUserSession     $userSession   The signed-in user.
	 * @param ObjectService    $objectService Reads the course and the group as the caller.
	 * @param BulkEnrolService $bulkEnrol     Creates the enrolments.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly BulkEnrolService $bulkEnrol,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Enrol a group in a course. Body: `courseId`, `cohortId`.
	 *
	 * @return JSONResponse 200 with `enrolled`, `skipped` and `refused` (learner ids); 401 or 404.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
	 */
	#[NoAdminRequired]
	public function enrol(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$course = $this->readable(id: $this->param(key: 'courseId'), schema: 'course');
		$cohort = $this->readable(id: $this->param(key: 'cohortId'), schema: 'cohort');
		if ($course === null || $cohort === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $this->bulkEnrol->enrol(course: $course, cohort: $cohort));
	}//end enrol()

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
}//end class
