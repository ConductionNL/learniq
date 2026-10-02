<?php

/**
 * Learniq Roll Call Controller
 *
 * Serves and saves the day's register of one group (the roll-call page,
 * `/attendance/roll-call`). Every access rule lives in RollCallService: a
 * group teacher opens their own groups, coordinators, administration-managers
 * and admins every group, and nobody else any.
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\RollCallService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The roll-call endpoints.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
 */
class RollCallController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param IUserSession    $userSession The signed-in user.
	 * @param RollCallService $rollCall    The register rules.
	 * @param LoggerInterface $logger      PSR logger.
	 * @param IL10N           $l10n        Messages.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly RollCallService $rollCall,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The register of a group on a day. The service checks who may open which group.
	 *
	 * @param string|null $cohortId  The group; empty for the caller's first group.
	 * @param string|null $date      The day, `Y-m-d`; empty for today.
	 * @param string|null $sessionId The lesson; empty for the day's first lesson.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	#[NoAdminRequired]
	public function show(?string $cohortId=null, ?string $date=null, ?string $sessionId=null): JSONResponse {
		return $this->answer(
			call: fn (\OCP\IUser $user): array => $this->rollCall->open(
				user: $user,
				cohortId: $this->orNull(value: $cohortId),
				date: $this->orNull(value: $date),
				sessionId: $this->orNull(value: $sessionId)
			)
		);
	}//end show()

	/**
	 * Save the marks of a group's register on a day. The service checks who may save which group.
	 *
	 * @param string                   $cohortId  The group.
	 * @param string                   $date      The day, `Y-m-d`.
	 * @param string|null              $sessionId The lesson; empty for the day's first lesson.
	 * @param array<int, mixed>        $marks     One mark per pupil.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
	 */
	#[NoAdminRequired]
	public function save(string $cohortId='', string $date='', ?string $sessionId=null, array $marks=[]): JSONResponse {
		return $this->answer(
			call: fn (\OCP\IUser $user): array => $this->rollCall->save(
				user: $user,
				cohortId: $cohortId,
				date: $date,
				sessionId: $this->orNull(value: $sessionId),
				marks: array_values($marks)
			)
		);
	}//end save()

	/**
	 * Run a call for the signed-in user and answer with its result or its refusal.
	 *
	 * @param callable(\OCP\IUser): array<string, mixed> $call The call.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $call): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to take the register.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $call($user));
		} catch (RollCallException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatus());
		} catch (Throwable $exception) {
			$this->logger->error('[RollCallController] The register could not be read or saved: {msg}', ['msg' => $exception->getMessage(), 'exception' => $exception]);
			return new JSONResponse(data: ['error' => $this->l10n->t('The register could not be read or saved. Try again.')], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end answer()

	/**
	 * A non-empty string, or null.
	 *
	 * @param string|null $value The value.
	 *
	 * @return string|null
	 */
	private function orNull(?string $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
