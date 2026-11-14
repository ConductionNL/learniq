<?php

/**
 * Learniq HR Field Ownership Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A LearnerProfile that the HR feed writes carries `hrSource`. On such a
 * profile the HR system owns `department`, `roles`, `managerId` and
 * `jobTitle`: a change to any of them made by hand in learniq is refused with
 * a message that names the source, because the next sync would overwrite it
 * and the person would gain or lose mandatory training for no visible reason.
 *
 * The feed itself passes: a write without a user session (a background sync
 * or import), or by the account named in the app setting `hr_feed_user` (the
 * integriq service account). A profile without `hrSource` stays editable.
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
 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#requirement-the-hr-system-owns-a-persons-department-roles-manager-and-job-title
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IUserSession;
use Throwable;

/**
 * Refuses manual edits to HR-owned fields on an HR-fed LearnerProfile.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#requirement-the-hr-system-owns-a-persons-department-roles-manager-and-job-title
 */
class HrFieldOwnershipGuard implements IEventListener {

	/**
	 * The fields the HR system owns on a profile it feeds.
	 */
	public const HR_FIELDS = ['department', 'roles', 'managerId', 'jobTitle'];

	/**
	 * App setting naming the account the HR feed writes as.
	 */
	public const FEED_USER_KEY = 'hr_feed_user';

	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the written object's schema slug.
	 * @param IUserSession                    $userSession    The writer.
	 * @param IAppConfig                      $appConfig      Holds the HR feed's account.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectUpdatingEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-hr-edits-an-hr-fed-department
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-a-hand-made-profile-stays-editable
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false || $this->isProfile(event: $event) === false) {
			return;
		}

		$old = ($event->getOldObject()?->getObject() ?? []);
		$source = (string)($old['hrSource'] ?? '');
		if ($source === '' || $this->isFeed() === true) {
			return;
		}

		$changed = $this->changedHrFields(old: $old, new: ($event->getNewObject()->getObject() ?? []));
		if ($changed === []) {
			return;
		}

		$event->setErrors(
			[
				'reason'  => 'hr-owned-field',
				'message' => sprintf(
					'%s comes from %s and can only be changed there.',
					implode(', ', $changed),
					$source
				),
			]
		);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether the written object is a learniq LearnerProfile.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return bool
	 */
	private function isProfile(ObjectUpdatingEvent $event): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $event->getNewObject()) === self::PROFILE_SCHEMA;
		} catch (Throwable) {
			// Not knowing the schema is not knowing it is ours.
			return false;
		}
	}//end isProfile()

	/**
	 * Whether the HR feed writes: no user session, or the configured feed account.
	 *
	 * @return bool
	 */
	private function isFeed(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		$feedUser = $this->appConfig->getValueString('learniq', self::FEED_USER_KEY, '');

		return $feedUser !== '' && $user->getUID() === $feedUser;
	}//end isFeed()

	/**
	 * The HR fields whose value the write changes. A field the write leaves
	 * out is not changed.
	 *
	 * @param array<string, mixed> $old The stored profile.
	 * @param array<string, mixed> $new The written profile.
	 *
	 * @return list<string>
	 */
	private function changedHrFields(array $old, array $new): array {
		$changed = [];
		foreach (self::HR_FIELDS as $field) {
			if (array_key_exists($field, $new) === false) {
				continue;
			}

			if ($this->normalised(value: $new[$field]) !== $this->normalised(value: ($old[$field] ?? null))) {
				$changed[] = $field;
			}
		}

		return $changed;
	}//end changedHrFields()

	/**
	 * A value compared without regard to list order or an empty-vs-null difference.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalised(mixed $value): mixed {
		if ($value === '' || $value === []) {
			return null;
		}

		if (is_array($value) === true) {
			sort($value);
		}

		return $value;
	}//end normalised()
}//end class
