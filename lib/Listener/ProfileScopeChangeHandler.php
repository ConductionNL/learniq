<?php

/**
 * Learniq Profile Scope Change Handler
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * When a LearnerProfile is created, or its `department`, `roles` or
 * `jobTitle` changes, checks every published regulation against that one
 * person (`RegulationAssignmentServiceInterface::assignPerson()`): a person
 * who comes into a regulation's scope is enrolled in its mandatory courses,
 * one who drops out has the open enrolment marked `outOfScopeSince`, never
 * withdrawn. The same path runs for the HR feed, an import and a manual edit,
 * because it reacts to the profile, not to the writer.
 *
 * ADR-031 legitimate exception: a cross-object reaction (profile -> every
 * published regulation -> enrolments) that no schema declaration expresses.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#requirement-a-change-of-department-roles-or-job-title-re-runs-the-assignment-for-that-person
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Kernel\Contracts\RegulationAssignmentServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-runs the mandatory-training assignment for one person whose scope changed.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#requirement-a-change-of-department-roles-or-job-title-re-runs-the-assignment-for-that-person
 */
class ProfileScopeChangeHandler implements IEventListener {

	/**
	 * The profile fields a regulation's audience reads.
	 */
	public const SCOPE_FIELDS = ['department', 'roles', 'jobTitle'];

	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param RegulationAssignmentServiceInterface $assignment     Assigns per person.
	 * @param ListenerSchemaResolverInterface      $schemaResolver Resolves the written object's schema slug.
	 * @param ITimeFactory                         $time           The clock, for today's date.
	 * @param LoggerInterface                      $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegulationAssignmentServiceInterface $assignment,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-a-new-colleague-joins-the-track-maintenance-department
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-someone-moves-to-a-department-in-scope
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-someone-leaves-the-department
	 */
	public function handle(Event $event): void {
		$profile = $this->changedProfile(event: $event);
		if ($profile === null) {
			return;
		}

		try {
			$result = $this->assignment->assignPerson(profile: $profile, today: $this->today());
		} catch (Throwable $e) {
			// The profile is already saved; a failed assignment is logged
			// and picked up by the next change or a manual assign.
			$this->logger->warning(
				'[ProfileScopeChangeHandler] Re-assignment for {user} failed: {msg}',
				['user' => (string)($profile['ncUserId'] ?? ''), 'msg' => $e->getMessage()]
			);
			return;
		}

		$this->logger->info(
			'[ProfileScopeChangeHandler] {user}: {created} enrolment(s) created, {out} marked out of scope.',
			['user' => (string)($profile['ncUserId'] ?? ''), 'created' => count($result['created']), 'out' => count($result['outOfScope'])]
		);
	}//end handle()

	/**
	 * The profile as it now stands when the event creates a LearnerProfile or
	 * changes one of its scope fields; null otherwise.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function changedProfile(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent) {
			return $this->profileOf(entity: $event->getObject());
		}

		if (($event instanceof ObjectUpdatedEvent) === false) {
			return null;
		}

		$profile = $this->profileOf(entity: $event->getNewObject());
		if ($profile === null) {
			return null;
		}

		$old = ($event->getOldObject()?->getObject() ?? []);
		foreach (self::SCOPE_FIELDS as $field) {
			if ($this->comparable(value: ($old[$field] ?? null)) !== $this->comparable(value: ($profile[$field] ?? null))) {
				return $profile;
			}
		}

		return null;
	}//end changedProfile()

	/**
	 * The entity's data when it is a learniq LearnerProfile, with its id.
	 *
	 * @param ObjectEntity $entity The written object.
	 *
	 * @return array<string, mixed>|null
	 */
	private function profileOf(ObjectEntity $entity): ?array {
		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $entity) !== self::PROFILE_SCHEMA) {
				return null;
			}
		} catch (Throwable) {
			return null;
		}

		$profile = ($entity->getObject() ?? []);
		$profile['id'] = ($profile['id'] ?? $entity->getUuid());

		return $profile;
	}//end profileOf()

	/**
	 * A scope value compared without regard to list order or empty-vs-null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function comparable(mixed $value): mixed {
		if ($value === '' || $value === []) {
			return null;
		}

		if (is_array($value) === true) {
			sort($value);
		}

		return $value;
	}//end comparable()

	/**
	 * Today, `Y-m-d`, in UTC.
	 *
	 * @return string
	 */
	private function today(): string {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->format('Y-m-d');
	}//end today()
}//end class
