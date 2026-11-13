<?php

/**
 * Learniq Synced Cohort Guard
 *
 * A cohort kept in step with the student administration system (one with
 * `externalSource`) takes its name, school year, programme year, learners and
 * teachers from that source. A manual save that changes one of them is
 * refused; notes, work groups, the report card template and everything else
 * learniq adds stay editable. The sync itself runs as the system (no user in
 * the session) and passes.
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
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-the-sources-fields-are-read-only-on-a-synced-cohort
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a manual change to the source's fields of a synced cohort.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-the-sources-fields-are-read-only-on-a-synced-cohort
 */
class SyncedCohortGuard implements IEventListener {

	/**
	 * The schema this listener guards.
	 */
	private const COHORT_SCHEMA = 'cohort';

	/**
	 * The fields the source owns, with how a refusal names them.
	 */
	public const SOURCE_FIELDS = [
		'name'           => 'name',
		'academicYear'   => 'school year',
		'programmeYear'  => 'programme year',
		'learnerIds'     => 'learners',
		'teacherIds'     => 'teachers',
		'externalId'     => 'external id',
		'externalSource' => 'source',
	];

	/**
	 * Fields only the sync writes: a manual save keeps the stored value.
	 */
	private const SYNC_STAMPS = ['syncedAt', 'syncChanges'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession                    $userSession    The acting user; none for the sync.
	 * @param LoggerInterface                 $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
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
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-the-sources-fields-are-read-only-on-a-synced-cohort
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false || $this->userSession->getUser() === null) {
			// The class roster sync runs as the system and owns these fields.
			return;
		}

		$entity = (new WrittenObject())->entityOf(event: $event);
		if ($this->isCohort(entity: $entity) === false) {
			return;
		}

		$old = ($event->getOldObject()?->getObject() ?? []);
		$source = (string)($old['externalSource'] ?? '');
		if ($source === '') {
			return;
		}

		$new = ($entity->getObject() ?? []);
		$changed = $this->changedField(old: $old, new: $new);
		if ($changed !== null) {
			$label = self::SOURCE_FIELDS[$changed];
			$event->setErrors(
				[
					'reason'  => 'cohort-field-from-source',
					'field'   => $changed,
					'message' => 'The '.$label.' of this group comes from the student administration ('.$source.') and cannot be changed in learniq.',
				]
			);
			$event->stopPropagation();
			$this->logger->info('[SyncedCohortGuard] Refused a change to {field} of a synced cohort.', ['field' => $changed]);
			return;
		}

		foreach (self::SYNC_STAMPS as $stamp) {
			if (array_key_exists($stamp, $old) === true) {
				$new[$stamp] = $old[$stamp];
			}
		}

		$entity->setObject($new);
	}//end handle()

	/**
	 * The first source field the save changes, or null.
	 *
	 * A field the save leaves out is not counted, so a partial save of
	 * learniq's own fields passes.
	 *
	 * @param array<string, mixed> $old The stored cohort.
	 * @param array<string, mixed> $new The cohort as it will be stored.
	 *
	 * @return string|null
	 */
	private function changedField(array $old, array $new): ?string {
		foreach (array_keys(self::SOURCE_FIELDS) as $field) {
			if (array_key_exists($field, $new) === false) {
				continue;
			}

			if ($this->normalised(value: $new[$field]) !== $this->normalised(value: $old[$field] ?? null)) {
				return $field;
			}
		}

		return null;
	}//end changedField()

	/**
	 * A value in a form where order of a list and the type of a number do not count.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalised(mixed $value): mixed {
		if (is_array($value) === true) {
			$items = array_map(fn (mixed $item): string => $this->scalarText(value: $item), $value);
			sort($items);

			return $items;
		}

		if ($value === null) {
			return '';
		}

		return $this->scalarText(value: $value);
	}//end normalised()

	/**
	 * A value as text: a scalar as itself, anything else as JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function scalarText(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end scalarText()

	/**
	 * Whether the entity is a cohort.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isCohort(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::COHORT_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break another app's writes.
			return false;
		}
	}//end isCohort()
}//end class
