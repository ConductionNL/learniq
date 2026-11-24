<?php

/**
 * Learniq Excuse Request Seen Controller
 *
 * Records a group teacher's first look at an absence report she opens on its
 * own page, and answers who saw it and when. The report is read with the
 * caller's own rights, and only a teacher named in its `teacherIds` stamps it
 * (ReportSeenStamp), so the endpoint never writes a report its caller cannot
 * read and never changes its lifecycle.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Attendance\ReportSeenStamp;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The seen stamp of one absence report.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
 */
class ExcuseRequestSeenController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param IUserSession    $userSession The signed-in user.
	 * @param IUserManager    $userManager Resolves the display name of whoever saw it.
	 * @param ReportSeenStamp $seen        Records the first look.
	 * @param LoggerInterface $logger      PSR logger.
	 * @param IL10N           $l10n        Messages.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly ReportSeenStamp $seen,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Stamp the caller's first look at one report, and answer who saw it and when.
	 *
	 * @param string $id The report's id.
	 *
	 * @return JSONResponse 200 with `seenBy`, `seenByName`, `seenAt` (empty when nobody saw it yet), 401 or 404.
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
	 * @no-admin-idor-exempt the per-object guard is the report read itself: ReportSeenStamp::seenOne() reads
	 *  it with the session user's own OpenRegister rights (a report she cannot read answers 404), and stamps
	 *  only when that user is in the report's teacherIds. The identity is never caller-supplied.
	 */
	#[NoAdminRequired]
	public function seen(string $id=''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to open this absence report.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$report = $this->seen->seenOne(reportId: $id, userId: $user->getUID());
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExcuseRequestSeenController] The absence report could not be read or stamped: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			$report = null;
		}

		if ($report === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('This absence report was not found.')], statusCode: Http::STATUS_NOT_FOUND);
		}

		$seenBy = (string)($report['seenBy'] ?? '');

		return new JSONResponse(
			data: [
				'seenBy' => $seenBy,
				'seenByName' => $this->nameOf(userId: $seenBy),
				'seenAt' => (string)($report['seenAt'] ?? ''),
			]
		);
	}//end seen()

	/**
	 * The display name of a user, or the id itself when the account is gone.
	 *
	 * @param string $userId The user id.
	 *
	 * @return string
	 */
	private function nameOf(string $userId): string {
		if ($userId === '') {
			return '';
		}

		return ($this->userManager->getDisplayName($userId) ?? $userId);
	}//end nameOf()
}//end class
