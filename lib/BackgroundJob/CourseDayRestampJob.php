<?php

/**
 * Learniq CourseDayRestampJob
 *
 * Re-derives the course days CourseDayStatusCascade buffered during a
 * request, each once.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
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

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Kernel\Contracts\CourseDayStatusServiceInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-derives each buffered course day once.
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
class CourseDayRestampJob extends ForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ActorForwarding                 $forwarding Clock, session, user manager and tenant context.
	 * @param LoggerInterface                 $log        Logger.
	 * @param CourseDayStatusServiceInterface $status     Re-derives one course day.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function __construct(
		ActorForwarding $forwarding,
		private readonly LoggerInterface $log,
		private readonly CourseDayStatusServiceInterface $status,
	) {
		parent::__construct(forwarding: $forwarding, logger: $log);
	}//end __construct()

	/**
	 * Re-derive each buffered course day once; one that fails does not stop the others.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach (self::cohortIds(entries: $context->getEntries()) as $cohortId) {
			try {
				$this->status->restamp(cohortId: $cohortId);
			} catch (Throwable $exception) {
				$context = ['msg' => $exception->getMessage(), 'cohortId' => $cohortId];
				$this->log->warning('[CourseDayRestampJob] A course day could not be re-derived: {msg}', $context);
			}
		}
	}//end runDeferred()

	/**
	 * The distinct non-empty cohort ids of the deferred entries, in order.
	 *
	 * @param iterable<array<string, mixed>> $entries The entries.
	 *
	 * @return list<string>
	 */
	private static function cohortIds(iterable $entries): array {
		$cohorts = [];
		foreach ($entries as $entry) {
			$cohorts[] = (string)($entry['cohortId'] ?? '');
		}

		return array_values(array_unique(array_filter($cohorts, static fn (string $id): bool => $id !== '')));
	}//end cohortIds()
}//end class
