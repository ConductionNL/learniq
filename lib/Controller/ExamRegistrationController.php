<?php

/**
 * Learniq Exam Registration Controller
 *
 * The administration's actions around a course day's exam with an external
 * examining body: open the registration list, read it, close it, export a
 * closed list, mark it sent, enter the results, issue the certificates of a
 * course day in rounding off, list the certificates expiring before a date,
 * and the administration's Today. Every endpoint is guarded by the
 * `exam-registration.manage` action.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\ExamRegistration\ExamRegistrationServices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The exam registration endpoints.
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */
class ExamRegistrationController extends Controller {

	/**
	 * The guarded action.
	 *
	 * @var string
	 */
	public const ACTION = 'exam-registration.manage';

	/**
	 * Constructor.
	 *
	 * @param IRequest                            $request      The request.
	 * @param CallerActions                       $caller       The signed-in user and the action matrix.
	 * @param ExamRegistrationServices            $services     The registration lists, the certificate issue and
	 *                                                          the administration's Today.
	 * @param LoggerInterface                     $logger       PSR logger.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function __construct(
		IRequest $request,
		private readonly CallerActions $caller,
		private readonly ExamRegistrationServices $services,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Open a registration list for a course day's exam.
	 *
	 * @param string $cohortId The course day.
	 * @param string $bodyName The examining body.
	 * @param string $closesAt The moment the list closes (ISO 8601).
	 * @param string $examDate The exam date (Y-m-d), empty for the course day's date.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	#[NoAdminRequired]
	public function open(string $cohortId='', string $bodyName='', string $closesAt='', string $examDate=''): JSONResponse {
		$fields = array_values(array_map('strval', (array)$this->request->getParam('requiredFields', [])));
		$exam   = null;
		if ($examDate !== '') {
			$exam = $examDate;
		}

		return $this->answer(
			call: fn (): array => $this->services->lists->open(
				cohortId: $cohortId,
				exam: ['bodyName' => $bodyName, 'closesAt' => $closesAt, 'examDate' => $exam, 'requiredFields' => $fields]
			),
			status: Http::STATUS_CREATED
		);
	}//end open()

	/**
	 * One list, refreshed while it is open.
	 *
	 * @param string $id The list.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		return $this->answer(call: fn (): array => $this->services->lists->show(id: $id));
	}//end show()

	/**
	 * Close a list before its moment.
	 *
	 * @param string $id The list.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	#[NoAdminRequired]
	public function close(string $id): JSONResponse {
		return $this->answer(call: fn (IUser $user): array => $this->services->lists->close(id: $id, userId: $user->getUID()));
	}//end close()

	/**
	 * The CSV of a closed list, for the examining body.
	 *
	 * @param string $id The list.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	#[NoAdminRequired]
	public function export(string $id): DataDownloadResponse|JSONResponse {
		$export = null;
		$answer = $this->answer(
			call: function () use ($id, &$export): array {
				$export = $this->services->lists->export(id: $id);
				return [];
			}
		);
		if ($export === null) {
			return $answer;
		}

		return new DataDownloadResponse(data: $export['csv'], filename: $export['filename'], contentType: 'text/csv; charset=utf-8');
	}//end export()

	/**
	 * Mark a closed list sent to the examining body.
	 *
	 * @param string $id The list.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	#[NoAdminRequired]
	public function markSent(string $id): JSONResponse {
		return $this->answer(call: fn (IUser $user): array => $this->services->lists->markSent(id: $id, userId: $user->getUID()));
	}//end markSent()

	/**
	 * Enter the examining body's result for one participant.
	 *
	 * @param string $id          The list.
	 * @param string $enrolmentId The participant's enrolment.
	 * @param string $result      `pass`, `fail`, or empty to clear it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
	 */
	#[NoAdminRequired]
	public function result(string $id, string $enrolmentId='', string $result=''): JSONResponse {
		return $this->answer(
			call: fn (IUser $user): array => $this->services->lists->recordResult(
				id: $id,
				enrolmentId: $enrolmentId,
				result: $result,
				userId: $user->getUID()
			)
		);
	}//end result()

	/**
	 * "Certificaten uitgeven": issue the certificates of a course day in rounding off.
	 *
	 * @param string $id The course day.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-thursdays-f-gassen-day
	 */
	#[NoAdminRequired]
	public function issueCertificates(string $id): JSONResponse {
		return $this->answer(call: fn (IUser $user): array => $this->services->certificates->issue(cohortId: $id, userId: $user->getUID()));
	}//end issueCertificates()

	/**
	 * The issued certificates expiring before a date.
	 *
	 * @param string $before The date (Y-m-d).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-20-exam-registration-and-certificate-issue/tasks.md
	 */
	#[NoAdminRequired]
	public function expiring(string $before=''): JSONResponse {
		return $this->answer(call: fn (): array => ['before' => $before, 'rows' => $this->services->today->expiring(before: $before)]);
	}//end expiring()

	/**
	 * The administration's Today.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	#[NoAdminRequired]
	public function today(): JSONResponse {
		return $this->answer(call: fn (): array => $this->services->today->today());
	}//end today()

	/**
	 * Check the action, run the call for the signed-in user and answer with its result or refusal.
	 *
	 * @param callable(IUser): array<string, mixed> $call   The call.
	 * @param int                                   $status The status of a success.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $call, int $status=Http::STATUS_OK): JSONResponse {
		$denied = (
			new CallerActionCheck(userSession: $this->caller->userSession, actionAuth: $this->caller->actionAuth)
		)->refusal(action: self::ACTION);
		if ($denied !== null) {
			return $denied;
		}

		$user = $this->caller->userSession->getUser();
		if ($user instanceof IUser === false) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $call($user), statusCode: $status);
		} catch (CourseDayException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatus());
		} catch (Throwable $exception) {
			$this->logger->error(
				'[ExamRegistrationController] The registration list could not be read or saved: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(
				data: ['error' => 'The registration list could not be read or saved. Try again.'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end answer()
}//end class
