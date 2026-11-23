<?php

/**
 * Learniq Guardian Conversation Service
 *
 * The conversation with a pupil's guardians, and the threads the pupil
 * started herself, on the pupil's page, read and answered through
 * portaliq's staff messaging. Only a member of staff who
 * may open the pupil's page asks; portaliq then answers only the threads
 * they take part in, so staff who are no participant read nothing.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 * @spec openspec/changes/portal-message-contacts/tasks.md#T4
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Reads and answers the guardian conversation of one pupil.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */
class GuardianConversationService {

	/**
	 * Status of a pupil without a conversation to answer.
	 */
	public const NOT_FOUND = 404;

	/**
	 * Status when portaliq cannot answer.
	 */
	public const UNAVAILABLE = 503;

	/**
	 * Constructor.
	 *
	 * @param RollCallAccess        $access      Which groups the caller may see.
	 * @param PupilPageReader       $reader      The pupil's profile.
	 * @param PortaliqStaffMessages $messages    Portaliq's staff messaging.
	 * @param IUserManager          $userManager Who is a member of staff, and their names.
	 * @param IL10N                 $l10n        Messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RollCallAccess $access,
		private readonly PupilPageReader $reader,
		private readonly PortaliqStaffMessages $messages,
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The newest messages with the pupil's guardians the caller takes part in.
	 *
	 * @param IUser  $user      The member of staff.
	 * @param string $learnerId The pupil's Nextcloud user id.
	 *
	 * @return array{available: bool, canReply: bool, messages: array<int, array<string, string>>}
	 *
	 * @throws RollCallException 403 when the caller may not open the pupil's page.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public function read(IUser $user, string $learnerId): array {
		$conversation = $this->conversation(user: $user, learnerId: $learnerId);
		if ($conversation === null) {
			return ['available' => false, 'canReply' => false, 'messages' => []];
		}

		return [
			'available' => true,
			'canReply' => ($conversation['replyThread'] !== null),
			'messages' => $conversation['messages'],
		];
	}//end read()

	/**
	 * Send a message to the pupil's guardians in the newest thread the caller takes part in.
	 *
	 * @param IUser  $user      The member of staff.
	 * @param string $learnerId The pupil's Nextcloud user id.
	 * @param string $message   The message.
	 *
	 * @return array{available: bool, canReply: bool, messages: array<int, array<string, string>>} The conversation after it.
	 *
	 * @throws RollCallException 400 empty message, 403 caller may not open the page, 404 no conversation, 503 portaliq unavailable.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public function reply(IUser $user, string $learnerId, string $message): array {
		$text = trim($message);
		if ($text === '') {
			throw new RollCallException(message: $this->l10n->t('Write a message first.'), status: RollCallException::BAD_REQUEST);
		}

		$conversation = $this->conversation(user: $user, learnerId: $learnerId);
		if ($conversation === null) {
			throw new RollCallException(message: $this->l10n->t('The messages with the parents cannot be reached right now.'), status: self::UNAVAILABLE);
		}

		if ($conversation['replyThread'] === null) {
			throw new RollCallException(
				message: $this->l10n->t('You have no conversation with the parents of this pupil yet.'),
				status: self::NOT_FOUND
			);
		}

		if ($this->messages->post(threadId: $conversation['replyThread'], staffRef: $user->getUID(), body: $text) === false) {
			throw new RollCallException(message: $this->l10n->t('The message could not be sent. Try again.'), status: self::UNAVAILABLE);
		}

		return $this->read(user: $user, learnerId: $learnerId);
	}//end reply()

	/**
	 * The caller's threads about the pupil, their messages and the reply thread; null when portaliq cannot answer.
	 *
	 * @param IUser  $user      The member of staff.
	 * @param string $learnerId The pupil's Nextcloud user id.
	 *
	 * @return array{messages: array<int, array<string, string>>, replyThread: string|null}|null
	 *
	 * @throws RollCallException 403 when the caller may not open the pupil's page.
	 */
	private function conversation(IUser $user, string $learnerId): ?array {
		$this->assertMayOpen(user: $user, learnerId: $learnerId);

		$staffRef = $user->getUID();
		$threads = $this->messages->threads(staffRef: $staffRef);
		if ($threads === null) {
			return null;
		}

		$about = GuardianConversation::aboutPupil(threads: $threads, pupilRefs: $this->pupilRefs(learnerId: $learnerId));
		$byThread = [];
		foreach ($about as $thread) {
			$threadId = GuardianConversation::idOf(row: $thread);
			$byThread[$threadId] = ($this->messages->messages(threadId: $threadId, staffRef: $staffRef) ?? []);
		}

		$messages = GuardianConversation::messages(
			byThread: $byThread,
			staffName: fn (string $ref): ?string => $this->staffName(ref: $ref)
		);

		return ['messages' => $messages, 'replyThread' => GuardianConversation::replyThread(threads: $about, messages: $messages)];
	}//end conversation()

	/**
	 * The display name of a sender who is a Nextcloud user (a member of staff), else null.
	 *
	 * @param string $ref The sender reference.
	 *
	 * @return string|null
	 */
	private function staffName(string $ref): ?string {
		if ($this->userManager->userExists($ref) === false) {
			return null;
		}

		return (string)($this->userManager->getDisplayName($ref) ?? $ref);
	}//end staffName()

	/**
	 * Refuse a caller for whom no group they may see holds the pupil.
	 *
	 * @param IUser  $user      The caller.
	 * @param string $learnerId The pupil.
	 *
	 * @return void
	 *
	 * @throws RollCallException 403.
	 */
	private function assertMayOpen(IUser $user, string $learnerId): void {
		if ($learnerId !== '') {
			foreach ($this->access->cohortsFor(user: $user) as $cohort) {
				if (in_array($learnerId, (array)($cohort['learnerIds'] ?? []), true) === true) {
					return;
				}
			}
		}

		throw new RollCallException(message: $this->l10n->t('You cannot open the page of this pupil.'), status: RollCallException::FORBIDDEN);
	}//end assertMayOpen()

	/**
	 * The references a thread may name the pupil by: their user id, profile id and enrolment ids.
	 *
	 * A guardian writes about the child's profile; the pupil writes from one of her own
	 * enrolments, so the threads she started name the enrolment.
	 *
	 * @param string $learnerId The pupil's Nextcloud user id.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/portal-message-contacts/tasks.md#T4
	 */
	private function pupilRefs(string $learnerId): array {
		$refs = [$learnerId];
		$profile = $this->reader->profileOf(learnerId: $learnerId);
		if ($profile !== null) {
			$refs[] = $this->reader->idOf(row: $profile);
			$refs[] = (string)($profile['uuid'] ?? '');
		}

		foreach ($this->reader->ofPupil(schema: 'enrolment', learnerId: $learnerId) as $enrolment) {
			$refs[] = $this->reader->idOf(row: $enrolment);
			$refs[] = (string)($enrolment['uuid'] ?? '');
		}

		return array_values(array_unique(array_filter($refs, static fn (string $ref): bool => $ref !== '')));
	}//end pupilRefs()
}//end class
