<?php

/**
 * Learniq Catalogue Controller
 *
 * The learner course catalogue in the app (enrolment-catalogue-self-signup):
 * list, sign up for a course or a programme, withdraw an own sign-up. Every
 * method is `#[NoAdminRequired]` with its check in the body: a write needs a
 * caller with a learner profile (403 otherwise) and acts only for that caller
 * (CatalogueSignUpService writes the caller as the learner and checks that an
 * enrolment to withdraw is the caller's own).
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Catalogue\CatalogueMessages;
use OCA\Learniq\Service\Catalogue\CatalogueReader;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Catalogue list, sign-up and withdraw for the signed-in learner.
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
class CatalogueController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request.
	 * @param IUserSession           $userSession The signed-in user.
	 * @param CatalogueReader        $reader      The catalogue.
	 * @param CatalogueSignUpService $signUps     Sign-up and withdraw rules.
	 * @param CatalogueMessages      $messages    Plain refusal reasons.
	 * @param LearnerRefResolver     $profiles    The caller's profile uuid.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CatalogueReader $reader,
		private readonly CatalogueSignUpService $signUps,
		private readonly CatalogueMessages $messages,
		private readonly LearnerRefResolver $profiles,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The catalogue for the caller. Query: `search`, `level`, `language`,
	 * `subject`, `provider`.
	 *
	 * @return JSONResponse 200 `{courses, programmes}`, or 401.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-provider-courses-show-their-provider
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$learner = $this->learner();
		if ($learner === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$filters = [
			'level' => $this->param(name: 'level'),
			'language' => $this->param(name: 'language'),
			'subject' => $this->param(name: 'subject'),
			'author' => $this->param(name: 'provider'),
		];

		return new JSONResponse(data: $this->reader->entries(userId: $learner->ncUserId, search: $this->param(name: 'search'), filters: $filters));
	}//end index()

	/**
	 * Sign the caller up for a course.
	 *
	 * @param string $id The course uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-an-open-course
	 */
	#[NoAdminRequired]
	public function signUpCourse(string $id): JSONResponse {
		$learner = $this->learner();
		if ($learner === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($learner->profileRef === '') {
			// Only a learner signs up or withdraws, and only for themselves:
			// the service writes this caller's own id and checks ownership.
			return new JSONResponse(data: ['error' => 'not_a_learner'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->signUps->requireOpenForSignUp(schema: 'course', id: $id) === false) {
			return $this->notFound(learner: $learner);
		}

		return $this->answer(outcome: $this->signUps->signUpCourse(learner: $learner, courseId: $id), learner: $learner);
	}//end signUpCourse()

	/**
	 * Sign the caller up for a programme.
	 *
	 * @param string $id The programme uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-a-track
	 */
	#[NoAdminRequired]
	public function signUpProgramme(string $id): JSONResponse {
		$learner = $this->learner();
		if ($learner === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($learner->profileRef === '') {
			// Only a learner signs up or withdraws, and only for themselves:
			// the service writes this caller's own id and checks ownership.
			return new JSONResponse(data: ['error' => 'not_a_learner'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->signUps->requireOpenForSignUp(schema: 'programme', id: $id) === false) {
			return $this->notFound(learner: $learner);
		}

		return $this->answer(outcome: $this->signUps->signUpProgramme(learner: $learner, programmeId: $id), learner: $learner);
	}//end signUpProgramme()

	/**
	 * Withdraw the caller's own sign-up.
	 *
	 * @param string $id The enrolment uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-changes-their-mind
	 */
	#[NoAdminRequired]
	public function withdraw(string $id): JSONResponse {
		$learner = $this->learner();
		if ($learner === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($learner->profileRef === '') {
			// Only a learner signs up or withdraws, and only for themselves:
			// the service writes this caller's own id and checks ownership.
			return new JSONResponse(data: ['error' => 'not_a_learner'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->signUps->requireOwnEnrolment(learner: $learner, enrolmentId: $id) === false) {
			return $this->notFound(learner: $learner);
		}

		return $this->answer(outcome: $this->signUps->withdraw(learner: $learner, enrolmentId: $id), learner: $learner);
	}//end withdraw()

	/**
	 * The signed-in user as a learner, with their profile when they have one.
	 *
	 * @return PortalLearner|null
	 */
	private function learner(): ?PortalLearner {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return new PortalLearner(
			profileRef: (string)($this->profiles->resolve(learnerId: $user->getUID()) ?? ''),
			ncUserId: $user->getUID(),
			tenantId: '',
			user: $user
		);
	}//end learner()

	/**
	 * A query parameter as a trimmed string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function param(string $name): string {
		$value = $this->request->getParam($name, '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end param()

	/**
	 * The not-found answer, the same as for a missing object.
	 *
	 * @param PortalLearner $learner The learner.
	 *
	 * @return JSONResponse
	 */
	private function notFound(PortalLearner $learner): JSONResponse {
		$outcome = new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'not_found'], reason: 'not-found');

		return $this->answer(outcome: $outcome, learner: $learner);
	}//end notFound()

	/**
	 * An outcome as a response, with the reason in the learner's words.
	 *
	 * @param PortalOutcome $outcome The outcome.
	 * @param PortalLearner $learner The learner.
	 *
	 * @return JSONResponse
	 */
	private function answer(PortalOutcome $outcome, PortalLearner $learner): JSONResponse {
		$body = $outcome->body;
		if ($outcome->reason !== null) {
			$body['message'] = $this->messages->message(reason: $outcome->reason, user: $learner->user);
		}

		return new JSONResponse(data: $body, statusCode: $outcome->status);
	}//end answer()
}//end class
