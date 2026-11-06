<?php

/**
 * Learniq Course Template Controller
 *
 * The course builder's server side: a course tree in three queries, Save as
 * template, and New course from template. `#[NoAdminRequired]` with the check
 * in OpenRegister: every read and write runs with the signed-in user's own
 * permissions, so a user who may not read the course or create the objects is
 * refused there.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\CourseTemplateRefusedException;
use OCA\Learniq\Service\Course\CourseTemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Course tree, Save as template and New course from template.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */
class CourseTemplateController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request     The request.
	 * @param IUserSession          $userSession The signed-in user.
	 * @param CourseTemplateService $templates   Course trees and templates.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CourseTemplateService $templates,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A course with its modules and their lessons, sorted by order.
	 *
	 * @param string $id The course uuid.
	 *
	 * @return JSONResponse 200 `{course, modules: [{...module, lessons}]}`, or 401 / 404.
	 * @no-admin-idor-exempt the guard is OpenRegister RBAC and multitenancy: CourseTemplateService reads and writes
	 *   course, lesson, course-template and curriculum-plan with `_rbac: true`, and each schema declares a top-level
	 *   `authorization` block in lib/Settings/learniq_register.json (the gate only reads `x-openregister.authorization`).
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	#[NoAdminRequired]
	public function structure(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		try {
			return new JSONResponse(data: $this->templates->structure(courseId: $id));
		} catch (CourseTemplateRefusedException $refusal) {
			return $this->refusal(refusal: $refusal);
		}
	}//end structure()

	/**
	 * Capture the course tree as a new CourseTemplate. The body carries
	 * `name` and an optional `description`.
	 *
	 * @param string $id The source course uuid.
	 *
	 * @return JSONResponse 201 `{id}`, or 401 / 403 / 404 / 422.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	#[NoAdminRequired]
	public function saveAsTemplate(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		try {
			$created = $this->templates->saveAsTemplate(
				courseId: $id,
				name: $this->stringParam(name: 'name'),
				description: $this->stringParam(name: 'description')
			);
		} catch (CourseTemplateRefusedException $refusal) {
			return $this->refusal(refusal: $refusal);
		}

		return new JSONResponse(data: $created, statusCode: Http::STATUS_CREATED);
	}//end saveAsTemplate()

	/**
	 * Create a new course tree from the template. The body carries `name`
	 * and an optional `language`.
	 *
	 * @param string $id The template uuid.
	 *
	 * @return JSONResponse 201 `{id}` of the new course, or 401 / 403 / 404 / 422.
	 * @no-admin-idor-exempt the guard is OpenRegister RBAC and multitenancy: CourseTemplateService reads and writes
	 *   course, lesson, course-template and curriculum-plan with `_rbac: true`, and each schema declares a top-level
	 *   `authorization` block in lib/Settings/learniq_register.json (the gate only reads `x-openregister.authorization`).
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	#[NoAdminRequired]
	public function instantiate(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		try {
			$created = $this->templates->instantiate(
				templateId: $id,
				name: $this->stringParam(name: 'name'),
				language: $this->stringParam(name: 'language')
			);
		} catch (CourseTemplateRefusedException $refusal) {
			return $this->refusal(refusal: $refusal);
		}

		return new JSONResponse(data: $created, statusCode: Http::STATUS_CREATED);
	}//end instantiate()

	/**
	 * A string request parameter; anything else reads as empty.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string
	 */
	private function stringParam(string $name): string {
		$value = $this->request->getParam($name, '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringParam()

	/**
	 * The answer without a signed-in user.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()

	/**
	 * The answer for a refusal.
	 *
	 * @param CourseTemplateRefusedException $refusal The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refusal(CourseTemplateRefusedException $refusal): JSONResponse {
		return new JSONResponse(
			data: ['error' => $refusal->getMessage(), 'reason' => $refusal->getReason()],
			statusCode: $refusal->getStatus()
		);
	}//end refusal()
}//end class
