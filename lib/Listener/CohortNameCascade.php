<?php

/**
 * Learniq CohortNameCascade
 *
 * When a group (Cohort) is renamed, writes the new name on every enrolment
 * in it, so `Enrolment.cohortName` never shows a guardian the old name
 * (site-guardian-portal-design). Runs after the cohort is stored. Each
 * enrolment save passes ReadableCopyStamp, which derives the same name from
 * the stored cohort. A failed read or save is logged and never undoes the
 * rename.
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-stamps the group name on a renamed cohort's enrolments.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
 */
class CohortNameCascade implements IEventListener {

	private const REGISTER = 'learniq';

	private const COHORT_SCHEMA = 'cohort';

	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * The most enrolments one rename re-stamps.
	 */
	private const MAX_ENROLMENTS = 1000;

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objectService  Reads and writes the enrolments.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Re-stamp the enrolments of a cohort whose name changed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $new);
		} catch (Throwable $exception) {
			return;
		}

		if ($slug !== self::COHORT_SCHEMA) {
			return;
		}

		$name = (($new->getObject() ?? [])['name'] ?? null);
		$oldName = (($event->getOldObject()?->getObject() ?? [])['name'] ?? null);
		$cohortId = $new->getUuid();
		if ($name === $oldName || is_string($name) === false || is_string($cohortId) === false || $cohortId === '') {
			return;
		}

		$this->restamp(cohortId: $cohortId, name: $name);
	}//end handle()

	/**
	 * Write the new name on each enrolment of the cohort that carries another.
	 *
	 * @param string $cohortId The cohort uuid.
	 * @param string $name     The cohort's new name.
	 *
	 * @return void
	 */
	private function restamp(string $cohortId, string $name): void {
		try {
			$enrolments = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema'   => self::ENROLMENT_SCHEMA,
						'cohortId' => $cohortId,
					],
					'limit'   => self::MAX_ENROLMENTS,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[CohortNameCascade] The enrolments of group {group} could not be read: {msg}',
				['group' => $cohortId, 'msg' => $exception->getMessage()]
			);
			return;
		}

		foreach ($enrolments as $enrolment) {
			$row = $enrolment;
			if (is_object($enrolment) === true && method_exists($enrolment, 'jsonSerialize') === true) {
				$row = (array)$enrolment->jsonSerialize();
			}

			if (is_array($row) === true) {
				$this->restampOne(row: $row, name: $name);
			}
		}
	}//end restamp()

	/**
	 * Save one enrolment with the new name, unless it already has it.
	 *
	 * @param array<string, mixed> $row  The enrolment.
	 * @param string               $name The cohort's new name.
	 *
	 * @return void
	 */
	private function restampOne(array $row, string $name): void {
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($uuid) === false || $uuid === '' || ($row['cohortName'] ?? null) === $name) {
			return;
		}

		// The row as read carries OpenRegister's `@self` block; saving it back
		// would make OpenRegister check the acting user's folder rights.
		$object = array_merge($row, ['cohortName' => $name]);
		unset($object['@self']);

		try {
			$this->objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: self::ENROLMENT_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[CohortNameCascade] Could not write the group name on enrolment {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
		}
	}//end restampOne()
}//end class
