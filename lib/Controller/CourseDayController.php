<?php

/**
 * Learniq Course Day Controller
 *
 * The planner's actions on a course day (a cohort of a training course): move
 * it on the board by hand, decide go or no-go, list the other days of the
 * course with places and move the participants of a cancelled day there.
 * Every endpoint is guarded by the `course-day.plan` action.
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
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\CourseDay\CourseDayStatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The course day endpoints.
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
class CourseDayController extends Controller {

	/**
	 * The guarded action.
	 *
	 * @var string
	 */
	public const ACTION = 'course-day.plan';

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request The request.
	 * @param CallerActions          $caller  The signed-in user and the action matrix.
	 * @param CourseDayStatusService $days    The course day rules.
	 * @param LoggerInterface        $logger  PSR logger.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function __construct(
		IRequest $request,
		private readonly CallerActions $caller,
		private readonly CourseDayStatusService $days,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Move a course day to a status by hand (a drag on the board).
	 *
	 * @param string $id     The cohort uuid.
	 * @param string $status The status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	#[NoAdminRequired]
	public function move(string $id, string $status=''): JSONResponse {
		return $this->answer(call: fn (IUser $user): array => ['dayStatus' => $this->days->move(cohortId: $id, status: $status, userId: $user->getUID())]);
	}//end move()

	/**
	 * Decide whether a course day goes ahead: `go`, `no-go`, or empty to undo.
	 *
	 * @param string $id       The cohort uuid.
	 * @param string $decision The decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	#[NoAdminRequired]
	public function decide(string $id, string $decision=''): JSONResponse {
		$chosen = null;
		if ($decision !== '') {
			$chosen = $decision;
		}

		return $this->answer(
			call: fn (IUser $user): array => [
				'dayStatus' => $this->days->decide(cohortId: $id, decision: $chosen, userId: $user->getUID()),
			]
		);
	}//end decide()

	/**
	 * The other coming days of the course with places.
	 *
	 * @param string $id The cohort uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	#[NoAdminRequired]
	public function alternatives(string $id): JSONResponse {
		return $this->answer(call: fn (IUser $user): array => ['days' => $this->days->alternatives(cohortId: $id)]);
	}//end alternatives()

	/**
	 * Move the participants of a cancelled course day to another day of the course.
	 *
	 * @param string $id       The cancelled day.
	 * @param string $targetId The day to move them to.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	#[NoAdminRequired]
	public function moveParticipants(string $id, string $targetId=''): JSONResponse {
		return $this->answer(
			call: fn (IUser $user): array => $this->days->moveParticipants(cohortId: $id, targetId: $targetId, userId: $user->getUID())
		);
	}//end moveParticipants()

	/**
	 * Check the action, run the call for the signed-in user and answer with its result or refusal.
	 *
	 * @param callable(IUser): array<string, mixed> $call The call.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $call): JSONResponse {
		$denied = (new CallerActionCheck(userSession: $this->caller->userSession, actionAuth: $this->caller->actionAuth))->refusal(action: self::ACTION);
		if ($denied !== null) {
			return $denied;
		}

		$user = $this->caller->userSession->getUser();
		if ($user instanceof IUser === false) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $call($user));
		} catch (CourseDayException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatus());
		} catch (Throwable $exception) {
			$this->logger->error(
				'[CourseDayController] The course day could not be read or saved: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'The course day could not be read or saved. Try again.'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end answer()
}//end class
