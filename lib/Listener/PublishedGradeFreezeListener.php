<?php

/**
 * Learniq Published Grade Freeze Listener
 *
 * Refuses a plain update that changes a published grade in a locked report
 * period. The four-eyes rule was bound to the publish and republish
 * transitions only, so a teacher who may update grade entries changed a
 * published grade in a locked period with a PATCH, without any transition or
 * correction (live pass 2 Oct, D4). A published grade in a locked period now
 * changes one way: revise, then republish, and the republish needs a
 * correction a second person approved (ReportPeriodLockGuard).
 *
 * Legitimate PHP per ADR-031: a write-time business rule across two objects
 * (the grade and the report period over it) that no schema declaration can
 * express; the shape follows LvsResultFreezeListener.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\Grading\ReportPeriodLocks;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Freezes the grade fields of a published grade entry in a locked period.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
class PublishedGradeFreezeListener implements IEventListener {

	private const SCHEMA = 'grade-entry';

	/**
	 * Fields that make up the grade, and the fields that place it in a period
	 * (moving it out of the period would escape the lock). Other fields
	 * (visibleFrom, correctionRequestId, learnerRef, learnerId on a merge)
	 * are bookkeeping that system writes keep up to date.
	 */
	private const GRADE_FIELDS = ['value', 'gradeScaleId', 'weight', 'componentId', 'period', 'curriculumPlanId'];

	private const REASON = 'grade-entry-locked';

	private const MESSAGE = 'This grade is published in a locked report period. Ask for a correction; after a'
		. ' second person approves it, revise the grade and republish it with the approved value.';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param ReportPeriodLocks $locks The locked report period over a grade entry.
	 * @param IUserSession $userSession The acting user.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ReportPeriodLocks $locks,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = $event->getOldObject();
		if ($old === null || $this->isPoliced(event: $event) === false) {
			return;
		}

		$stored = $old->getObject();
		$changed = $this->changedGradeFields(old: $stored, new: $event->getNewObject()->getObject());
		if (($stored['lifecycle'] ?? null) !== 'published' || $changed === []) {
			return;
		}

		$period = $this->locks->lockedPeriodFor(entry: $stored);
		if ($period === null) {
			return;
		}

		$event->setErrors(['reason' => self::REASON, 'message' => self::MESSAGE]);
		$event->stopPropagation();
		$this->logger->info(
			'[PublishedGradeFreezeListener] Refused a change to {fields} of published grade entry {id} in locked report period {period} by {actor}.',
			[
				'fields' => implode(', ', $changed),
				'id'     => $stored['id'] ?? ($old->getUuid() ?? ''),
				'period' => $period['id'] ?? '',
				'actor'  => $this->userSession->getUser()?->getUID() ?? '',
			]
		);
	}//end handle()

	/**
	 * Whether this write is a grade-entry update by a signed-in user. Nobody,
	 * admin included, changes a locked grade alone; a write without a session
	 * is a system job (score poll, repair) and is not a person's change.
	 *
	 * @param ObjectUpdatingEvent $event The event.
	 *
	 * @return bool True when the write must be checked.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	private function isPoliced(ObjectUpdatingEvent $event): bool {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $event->getNewObject());
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return false;
		}

		return $slug === self::SCHEMA && $this->userSession->getUser() !== null;
	}//end isPoliced()

	/**
	 * The grade fields an update changes.
	 *
	 * @param array<string,mixed> $old The stored entry.
	 * @param array<string,mixed> $new The entry as it would be saved.
	 *
	 * @return array<int,string> The changed field names.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	private function changedGradeFields(array $old, array $new): array {
		$changed = [];
		foreach (self::GRADE_FIELDS as $field) {
			if ($this->same(left: ($old[$field] ?? null), right: ($new[$field] ?? null)) === false) {
				$changed[] = $field;
			}
		}

		return $changed;
	}//end changedGradeFields()

	/**
	 * Compare two stored values the way they round-trip through JSON, so an
	 * integer 6 and a float 6.0 are the same grade.
	 *
	 * @param mixed $left One value.
	 * @param mixed $right The other value.
	 *
	 * @return bool True when equal.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	private function same(mixed $left, mixed $right): bool {
		if (is_numeric($left) === true && is_numeric($right) === true) {
			return abs((float)$left - (float)$right) < 0.000001;
		}

		return $left === $right;
	}//end same()
}//end class
