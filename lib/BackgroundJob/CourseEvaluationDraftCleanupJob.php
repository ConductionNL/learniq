<?php

/**
 * Learniq course evaluation draft cleanup job.
 *
 * Removes course-evaluation-response rows left in `draft`. A draft normally
 * lives for one request: CourseEvaluationAnswerService saves it and submits
 * it in the same request, and deletes it again when the submit is refused. A
 * request that dies between the save and either outcome (killed process,
 * timeout, restart, a failing delete) leaves the draft behind, and the draft
 * rule of #1715 lets every signed-in user read and change a draft. Nothing
 * else ever removes it.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes left-over course evaluation drafts.
 *
 * @psalm-api
 *
 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 */
class CourseEvaluationDraftCleanupJob extends TimedJob {

	/**
	 * App config key: the age in seconds after which a draft is removed.
	 */
	public const MAX_AGE_KEY = 'evaluation_draft_max_age_seconds';

	/**
	 * Default age: one hour. A draft is needed only while the request that
	 * saved it runs; Nextcloud's documented PHP max_execution_time ceiling
	 * is 3600 s, so no live request can still need an older draft.
	 */
	public const DEFAULT_MAX_AGE_SECONDS = 3600;

	/**
	 * Floor for a configured age: ten minutes, so a misconfigured value can
	 * never remove a draft a submit still in flight needs.
	 */
	public const MIN_MAX_AGE_SECONDS = 600;

	/**
	 * Runs every fifteen minutes.
	 */
	public const INTERVAL_SECONDS = 900;

	/**
	 * Drafts handled per run; a crash leaves one draft, so a page is plenty.
	 */
	private const PAGE = 500;

	private const REGISTER = 'learniq';

	private const SCHEMA = 'course-evaluation-response';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time          Nextcloud time factory.
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param IAppConfig      $appConfig     App config, for the age.
	 * @param LoggerInterface $logger        Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Remove every draft older than the configured age, as the system.
	 *
	 * Only rows whose lifecycle is `draft` are read, and each is checked
	 * again before it is removed, so a submitted row is never touched. A
	 * draft without a creation time is left alone. The delete is permanent:
	 * a draft is not a record, and a tombstone would keep its answers.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is TimedJob's.
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
	 */
	protected function run(mixed $argument): void {
		$maxAge = max(
			self::MIN_MAX_AGE_SECONDS,
			$this->appConfig->getValueInt(self::REGISTER, self::MAX_AGE_KEY, self::DEFAULT_MAX_AGE_SECONDS)
		);
		$cutoff = $this->time->now()->getTimestamp() - $maxAge;

		$drafts = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'lifecycle' => 'draft'],
				'limit' => self::PAGE,
			],
			_rbac: false,
			_multitenancy: false,
		);

		$removed = 0;
		foreach ($drafts as $draft) {
			if ($this->isStaleDraft(draft: $draft, cutoff: $cutoff) === false) {
				continue;
			}

			$removed += $this->remove(uuid: (string)$draft->getUuid());
		}

		$this->logger->info(
			message: '[CourseEvaluationDraftCleanupJob] Left-over course evaluation drafts older than {maxAge} s removed: {removed}',
			context: ['removed' => $removed, 'maxAge' => $maxAge]
		);
	}//end run()

	/**
	 * Whether a row is a draft created before the cutoff.
	 *
	 * @param mixed $draft  A row from findAll.
	 * @param int   $cutoff Unix time; drafts created before it are stale.
	 *
	 * @return bool
	 */
	private function isStaleDraft(mixed $draft, int $cutoff): bool {
		// getCreated() is an Entity magic accessor, so method_exists() cannot see it.
		if ($draft instanceof ObjectEntity === false) {
			return false;
		}

		$data = $draft->jsonSerialize();
		$created = $draft->getCreated();
		if (($data['lifecycle'] ?? null) !== 'draft' || $created instanceof DateTimeInterface === false) {
			return false;
		}

		return $created->getTimestamp() < $cutoff;
	}//end isStaleDraft()

	/**
	 * Permanently remove one draft; a failure is logged and counts as none.
	 *
	 * @param string $uuid The draft's uuid.
	 *
	 * @return int 1 when removed, else 0.
	 */
	private function remove(string $uuid): int {
		try {
			$this->objectService->deleteObject(
				uuid: $uuid,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				permanent: true,
			);
			return 1;
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[CourseEvaluationDraftCleanupJob] A left-over draft could not be removed: {error}',
				context: ['uuid' => $uuid, 'error' => $exception->getMessage()]
			);
			return 0;
		}
	}//end remove()
}//end class
