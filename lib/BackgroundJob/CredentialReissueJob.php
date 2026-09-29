<?php

/**
 * Learniq Credential Reissue Job
 *
 * The queued run of a bulk reissue (credentials-bulk-reissue), added by
 * CredentialReissueController with the course, the run id, the reason and
 * the staff member who started it. The work is CredentialReissueService's;
 * this job only resolves the staff member's account, so the transitions run
 * as them.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
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
 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\CredentialReissueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Runs one bulk reissue.
 *
 * @psalm-api
 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */
class CredentialReissueJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time     Nextcloud time factory.
	 * @param CredentialReissueService $reissues The reissue run.
	 * @param IUserManager             $users    The account of who started it.
	 * @param LoggerInterface          $logger   Logs a run that cannot start.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CredentialReissueService $reissues,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the reissue named by the argument.
	 *
	 * @param mixed $argument `{courseId, runId, reason, by}`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	protected function run(mixed $argument): void {
		if (is_array($argument) === false) {
			$argument = [];
		}

		$user = $this->users->get((string)($argument['by'] ?? ''));
		if ($user === null || (string)($argument['courseId'] ?? '') === '' || (string)($argument['runId'] ?? '') === '') {
			$this->logger->error('[CredentialReissueJob] A reissue run could not start: its course, run id or account is missing.');
			return;
		}

		$this->reissues->run(
			courseId: (string)$argument['courseId'],
			runId: (string)$argument['runId'],
			reason: (string)($argument['reason'] ?? ''),
			actor: $user
		);
	}//end run()
}//end class
