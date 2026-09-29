<?php

/**
 * Learniq Credential Reissue Controller
 *
 * Bulk reissue of a course's certificates (credentials-bulk-reissue): the
 * preview (how many are issued, revoked and expired, and the last run) and
 * the start of a run with a required reason. `hr`, `compliance-officers` and
 * admins only, checked in each method; an instructor gets 403.
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
 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\BackgroundJob\CredentialReissueJob;
use OCA\Learniq\Service\CredentialReissueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Preview and start a bulk reissue.
 *
 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */
class CredentialReissueController extends Controller {

	/**
	 * Groups that may reissue.
	 */
	private const STAFF_GROUPS = ['hr', 'compliance-officers'];

	/**
	 * App config key prefix of the last run id per course.
	 */
	private const LAST_RUN_PREFIX = 'reissue_last_';

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request      The request.
	 * @param IUserSession             $userSession  The signed-in user.
	 * @param IGroupManager            $groupManager Group membership and admin checks.
	 * @param CredentialReissueService $reissues     Preview and run summaries.
	 * @param IJobList                 $jobs         Queues the run.
	 * @param ISecureRandom            $random       Makes the run id.
	 * @param IAppConfig               $config       Remembers the last run per course.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly CredentialReissueService $reissues,
		private readonly IJobList $jobs,
		private readonly ISecureRandom $random,
		private readonly IAppConfig $config,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The counts for the course and the summary of its last run.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return JSONResponse 200 `{issued, revoked, expired, lastRun}`, or 403.
	 *
	 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	#[NoAdminRequired]
	public function preview(string $courseId): JSONResponse {
		if ($this->isStaff() === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$lastRun = $this->config->getValueString(app: Application::APP_ID, key: self::LAST_RUN_PREFIX . md5($courseId), default: '');
		$summary = null;
		if ($lastRun !== '') {
			$summary = $this->reissues->summary(runId: $lastRun) ?? ['runId' => $lastRun, 'status' => 'queued'];
		}

		return new JSONResponse(data: $this->reissues->preview(courseId: $courseId) + ['lastRun' => $summary]);
	}//end preview()

	/**
	 * Queue a reissue run. Body: `reason` (required).
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return JSONResponse 202 `{runId}`, or 403 / 422.
	 *
	 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	#[NoAdminRequired]
	public function start(string $courseId): JSONResponse {
		if ($this->isStaff() === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$reason = $this->request->getParam('reason', '');
		if (is_string($reason) === false || trim($reason) === '') {
			return new JSONResponse(data: ['error' => 'reason_required'], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$runId = $this->random->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$this->jobs->add(
			CredentialReissueJob::class,
			['courseId' => $courseId, 'runId' => $runId, 'reason' => trim($reason), 'by' => (string)$this->userSession->getUser()?->getUID()]
		);
		$this->config->setValueString(app: Application::APP_ID, key: self::LAST_RUN_PREFIX . md5($courseId), value: $runId);

		return new JSONResponse(data: ['runId' => $runId], statusCode: Http::STATUS_ACCEPTED);
	}//end start()

	/**
	 * Whether the caller is an admin or in a reissue group.
	 *
	 * @return bool
	 */
	private function isStaff(): bool {
		$userId = (string)$this->userSession->getUser()?->getUID();
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()
}//end class
