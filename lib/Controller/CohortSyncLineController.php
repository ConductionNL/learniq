<?php

/**
 * Learniq CohortSyncLineController
 *
 * `GET /api/cohorts/sync-line`: the line under the groups list's title,
 * `{ line }`, over the groups the caller may open ('' when none is synced).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Cohort\CohortSyncLine;
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
 * The groups list's sync line.
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
 */
class CohortSyncLineController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param IUserSession    $userSession The signed-in user.
	 * @param RollCallAccess  $access      The groups the caller may open, and her zone.
	 * @param LoggerInterface $logger      PSR logger.
	 * @param IL10N           $l10n        Messages.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly RollCallAccess $access,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The line, or '' when no group the caller may open is synced.
	 *
	 * @return JSONResponse `{ line }`, or 401 / 503.
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
	 * @no-admin-idor-exempt reads only the groups RollCallAccess::cohortsFor() returns for the session user.
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to open this page.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$words = new CohortSyncLine();
			$run   = $words->lastRun(cohorts: $this->access->cohortsFor(user: $user));
			if ($run === null) {
				return new JSONResponse(data: ['line' => '']);
			}

			$line = $this->l10n->n(
				'Updated from %1$s at %2$s, %3$s change',
				'Updated from %1$s at %2$s, %3$s changes',
				$run['changes'],
				[$words->sourceName(source: $run['source']), $words->timeOf(at: $run['at'], zone: $this->access->zone(user: $user)), (string)$run['changes']]
			);
			return new JSONResponse(data: ['line' => $line]);
		} catch (Throwable $exception) {
			$this->logger->warning('[CohortSyncLineController] No sync line: {msg}', ['msg' => $exception->getMessage()]);
			return new JSONResponse(data: ['line' => ''], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end show()
}//end class
