<?php

/**
 * Learniq Attendance Summary Listener
 *
 * Keeps AttendanceSummary current: every create, update and delete of an
 * AttendanceRecord defers a recount of that learner's school year to
 * AttendanceSummaryRecomputeJob. The listener itself only reads the event, so
 * a roll-call of thirty pupils costs the write path nothing; ADR-078 makes
 * post-write work asynchronous by default.
 *
 * An update that moves a record to another lesson or pupil also recounts what
 * it moved away from, so the old school year does not keep the absence.
 *
 * ADR-031 legitimate exception: an event-to-object-write bridge (a read model
 * kept by the server) that a schema declaration cannot express.
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\AttendanceSummaryRecomputeJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Defers a summary recount for every AttendanceRecord write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */
class AttendanceSummaryListener implements IEventListener {

	private const REGISTER = 'learniq';
	private const RECORD_SCHEMA = 'attendance-record';

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral       Buffers the recount for after the request.
	 * @param ListenerSchemaResolver  $schemaResolver Resolves the entity's register and schema slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolver $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Defer a recount for the written record, and for its old state when it moved.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false
			&& $event instanceof ObjectUpdatedEvent === false
			&& $event instanceof ObjectDeletedEvent === false
		) {
			return;
		}

		$entity = $event->getObject();
		if ($this->isRecord(entity: $entity) === false) {
			return;
		}

		$this->deferFor(entity: $entity);

		$old = null;
		if ($event instanceof ObjectUpdatedEvent === true) {
			$old = $event->getOldObject();
		}

		if ($old !== null && $this->keyOf(entity: $old) !== $this->keyOf(entity: $entity)) {
			$this->deferFor(entity: $old);
		}
	}//end handle()

	/**
	 * The learner and lesson a record state counts for.
	 *
	 * @param ObjectEntity $entity The record.
	 *
	 * @return string
	 */
	private function keyOf(ObjectEntity $entity): string {
		$record = ($entity->getObject() ?? []);

		return $this->text(value: ($record['learnerId'] ?? null)) . '|' . $this->text(value: ($record['sessionId'] ?? null));
	}//end keyOf()

	/**
	 * Defer one recount entry for a record state.
	 *
	 * @param ObjectEntity $entity The record.
	 *
	 * @return void
	 */
	private function deferFor(ObjectEntity $entity): void {
		$record = ($entity->getObject() ?? []);
		$learnerId = $this->text(value: ($record['learnerId'] ?? null));
		if ($learnerId === '') {
			return;
		}

		$sessionId = $this->text(value: ($record['sessionId'] ?? null));
		$this->deferral->defer(
			jobClass: AttendanceSummaryRecomputeJob::class,
			entry: [
				'learnerId' => $learnerId,
				'sessionId' => $sessionId,
				'tenantId' => $this->text(value: ($record['tenant_id'] ?? null)),
				'learnerRef' => $this->text(value: ($record['learnerRef'] ?? null)),
			],
			dedupeKey: $this->keyOf(entity: $entity)
		);
	}//end deferFor()

	/**
	 * Whether the entity is a learniq AttendanceRecord.
	 *
	 * @param ObjectEntity $entity The written object.
	 *
	 * @return bool
	 */
	private function isRecord(ObjectEntity $entity): bool {
		return $this->schemaResolver->registerSlug(entity: $entity) === self::REGISTER
			&& $this->schemaResolver->schemaSlug(entity: $entity) === self::RECORD_SCHEMA;
	}//end isRecord()

	/**
	 * A string value, or ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()
}//end class
