<?php

/**
 * Learniq parent portal collections
 *
 * The parent-audience collections and actions that sit beside the core parent
 * manifest in PortalContributionProvider: the groups the guardian's children
 * are in (which group news reaches the guardian), the parent-evening rounds,
 * slots and bookings, the booking action, and the child cross-reference and
 * options every guardian create uses. Like the provider, this class is plain:
 * no portaliq imports and no constructor dependencies, so it stays inert when
 * portaliq is not installed.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-parent-conference-booking/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Parent-audience collections and actions beside the core parent manifest.
 *
 * @spec openspec/changes/portal-parent-conference-booking/specs/portal-contribution/spec.md
 */
class ParentPortalCollections {

	private const REGISTER = 'learniq';

	/**
	 * The groups the guardian's children are enrolled in, not listed in the
	 * portal menu. Portaliq reads it to know which group news reaches the
	 * guardian (`guardianAudience.groups`).
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/portal-parent-conference-booking/specs/portal-contribution/spec.md
	 */
	public function groupMembershipsCollection(array $childJoin): array {
		return [
			'id' => 'parentGroupMemberships',
			'register' => self::REGISTER,
			'schema' => 'enrolment',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'guardianRef',
			'via' => $childJoin,
			'groupByField' => 'learnerRef',
			'label' => "Your child's group",
			'listable' => false,
			'minTrust' => 'substantial',
			'fields' => [
				'learnerRef',
				'cohortId',
			],
			'columns' => [
				['field' => 'cohortId', 'label' => 'Group'],
			],
		];

	}//end groupMembershipsCollection()

	/**
	 * The grades on the child's published report cards, for the guardian.
	 *
	 * A primary school records no grade entries, only report cards, so
	 * `parentGrades` (over `grade-entry`) stays empty for its guardians. This
	 * collection reads `report-card` through the same reverse `via` join as
	 * every parent read, behind the same lifecycle filter as
	 * `parentReportCards`: only a card in `published-to-parents` is ever
	 * read, so a draft or a card still in review never reaches a guardian.
	 *
	 * Portaliq shows a nested list as one cell and leaves every uuid out, so
	 * `subjectGrades` itself would read "7,9, Yes" without a subject or a
	 * period. The collection shows the readable copies the server keeps
	 * beside it instead (ReportCardGradeLines): `periodName` and
	 * `gradeLines`, one line per subject. Pupil tracking results (Cito,
	 * `lvs-result`) are not report card grades and are not read here.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/portal-parent-report-card-grades/specs/portal-contribution/spec.md#requirement-the-parent-audience-reads-the-grades-on-the-childs-published-report-cards
	 */
	public function reportCardGradesCollection(array $childJoin): array {
		return [
			'id' => 'parentReportCardGrades',
			'register' => self::REGISTER,
			'schema' => 'report-card',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'guardianRef',
			'via' => $childJoin,
			'groupByField' => 'learnerRef',
			'filter' => ['lifecycle' => 'published-to-parents'],
			'label' => "My child's report card grades",
			'listable' => true,
			'minTrust' => 'substantial',
			'fields' => [
				'learnerRef',
				'periodName',
				'gradeLines',
			],
			'columns' => [
				['field' => 'periodName', 'label' => 'Period'],
				['field' => 'gradeLines', 'label' => 'Grades'],
			],
		];

	}//end reportCardGradesCollection()

	/**
	 * The guardian's parent-teacher conference collections: the rounds open
	 * to one of their children, the free times they can book, their bookings
	 * and the conversation times.
	 *
	 * All four go through the same reverse `via` join as every parent read. A round is matched on `invitedLearnerRefs`, which
	 * the round's `send-invitations` transition fills
	 * (ConferenceInvitationAction), and only a round in `booking-open` is
	 * listed. A free time is matched on `eligibleLearnerRefs` (the invited
	 * pupils of that teacher's groups, ConferenceFreeSlotGenerator) and only
	 * a slot in `free` is listed. The free times come BEFORE the times:
	 * portaliq fills the booking form's time picker from the first
	 * collection over `conference-slot`.
	 *
	 * Field names here are read by the portal calendar (lane lq-record):
	 * `startsAt`, `endsAt`, `teacherId`, `teacherName`, `location`,
	 * `lifecycle`, `conferenceRoundId`, `slotLabel`. Add, never rename.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>> Parent conference collections.
	 *
	 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
	 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
	 */
	public function conferenceCollections(array $childJoin): array {
		return [
			[
				'id' => 'parentConferenceRounds',
				'register' => self::REGISTER,
				'schema' => 'conference-round',
				'scopeField' => 'invitedLearnerRefs',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'filter' => ['lifecycle' => 'booking-open'],
				'label' => 'Parent-teacher conferences you can book',
				'listable' => true,
				'minTrust' => 'substantial',
				'fields' => [
					'name',
					'bookingOpensAt',
					'bookingClosesAt',
					'slotDurationMinutes',
					'bookingMode',
				],
				'columns' => [
					['field' => 'name', 'label' => 'Conference round'],
					['field' => 'bookingClosesAt', 'label' => 'Book before'],
					['field' => 'slotDurationMinutes', 'label' => 'Minutes per conversation'],
				],
			],
			$this->freeSlotsCollection(childJoin: $childJoin),
			[
				'id' => 'parentConferenceSignups',
				'register' => self::REGISTER,
				'schema' => 'conference-signup',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'groupByField' => 'learnerRef',
				'label' => 'Your conference bookings',
				'listable' => true,
				'minTrust' => 'substantial',
				'fields' => [
					'conferenceRoundId',
					'learnerRef',
					'requestedTeacherIds',
					'notes',
					'lifecycle',
					'slotId',
					'slotLabel',
					'teacherName',
					'startsAt',
					'endsAt',
					'declineNote',
				],
				'columns' => [
					['field' => 'slotLabel', 'label' => 'Time'],
					['field' => 'requestedTeacherIds', 'label' => 'With', 'render' => 'user'],
					['field' => 'notes', 'label' => 'Your note'],
					['field' => 'lifecycle', 'label' => 'Status', 'valueLabels' => PortalValueLabels::SIGNUP_STATUS],
					['field' => 'declineNote', 'label' => 'Reason for declining'],
				],
			],
			[
				'id' => 'parentConferenceSlots',
				'register' => self::REGISTER,
				'schema' => 'conference-slot',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'groupByField' => 'learnerRef',
				'label' => 'Your conference times',
				'listable' => true,
				'minTrust' => 'substantial',
				'fields' => [
					'learnerRef',
					'teacherId',
					'startsAt',
					'endsAt',
					'location',
					'lifecycle',
					'conferenceRoundId',
					'teacherName',
					'slotLabel',
					'declineNote',
				],
				'columns' => [
					['field' => 'startsAt', 'label' => 'Starts'],
					['field' => 'endsAt', 'label' => 'Ends'],
					['field' => 'teacherId', 'label' => 'With', 'render' => 'user'],
					['field' => 'location', 'label' => 'Where'],
					['field' => 'lifecycle', 'label' => 'Status', 'valueLabels' => PortalValueLabels::SLOT_STATUS],
					['field' => 'declineNote', 'label' => 'Reason for declining'],
				],
				'rowActions' => ['cancelConferenceTime'],
			],
		];

	}//end conferenceCollections()

	/**
	 * The free times the guardian can book for one of their children.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
	 */
	private function freeSlotsCollection(array $childJoin): array {
		return [
			'id' => 'parentConferenceFreeSlots',
			'register' => self::REGISTER,
			'schema' => 'conference-slot',
			'scopeField' => 'eligibleLearnerRefs',
			'scopeClaim' => 'guardianRef',
			'via' => $childJoin,
			'filter' => ['lifecycle' => 'free'],
			'label' => 'Free times you can book',
			'listable' => true,
			'minTrust' => 'substantial',
			'fields' => [
				'conferenceRoundId',
				'teacherId',
				'teacherName',
				'startsAt',
				'endsAt',
				'location',
				'slotLabel',
				'lifecycle',
			],
			'columns' => [
				['field' => 'startsAt', 'label' => 'Starts'],
				['field' => 'endsAt', 'label' => 'Ends'],
				['field' => 'teacherId', 'label' => 'With', 'render' => 'user'],
				['field' => 'location', 'label' => 'Where'],
			],
		];

	}//end freeSlotsCollection()

	/**
	 * The change rule that tells a guardian the teacher answered their
	 * booking: acknowledged, or declined with the teacher's note.
	 *
	 * The booking holds the guardian's learniq reference (`guardianRef`), not
	 * their portal reference, so the rule names its recipients by that claim
	 * (portaliq claim-addressed-change-notices): portaliq tells the portal
	 * accounts whose `claims.learniq.guardianRef` the booking holds, and only
	 * when that account may read the booking through this collection. Only
	 * `acknowledged` and `declined` have words, so a booking the parent
	 * cancels themselves, or any other move, is not reported. Placeholders
	 * name fields the bookings collection shows the guardian; portaliq drops
	 * the rule otherwise.
	 *
	 * @return array<string, mixed> The rule.
	 *
	 * @spec openspec/changes/conference-answer-notice/specs/parent-conferences/spec.md
	 */
	public function conferenceAnsweredRule(): array {
		return [
			'ruleKey' => 'conference.answered',
			'collection' => 'parentConferenceSignups',
			'on' => ['field' => 'lifecycle', 'operator' => 'changed'],
			'titleField' => 'slotLabel',
			'recipients' => ['field' => 'guardianRef', 'claim' => 'guardianRef'],
			'messages' => [
				'acknowledged' => [
					'subject' => [
						'nl' => 'Gesprekstijd bevestigd',
						'en' => 'Conference time confirmed',
					],
					'body' => [
						'nl' => 'De leerkracht heeft uw gesprekstijd bevestigd: {startsAt|datetime}, met {teacherName}.',
						'en' => 'The teacher confirmed your conference time: {startsAt|datetime}, with {teacherName}.',
					],
				],
				'declined' => [
					'subject' => [
						'nl' => 'Gesprekstijd gaat niet door',
						'en' => 'Conference time declined',
					],
					'body' => [
						'nl' => 'De leerkracht kan helaas niet op {startsAt|datetime}. U kunt een andere tijd kiezen. Toelichting van de leerkracht: {declineNote}',
						'en' => 'The teacher cannot make {startsAt|datetime}. You can pick another time. The teacher\'s note: {declineNote}',
					],
				],
			],
		];

	}//end conferenceAnsweredRule()

	/**
	 * Every conference action of the guardian: book a free time, cancel it,
	 * and ask for a conversation in a round the school plans.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
	 */
	public function conferenceActions(): array {
		return [
			$this->bookSlotAction(),
			$this->cancelTimeAction(),
			$this->conferenceSignupAction(),
		];

	}//end conferenceActions()

	/**
	 * The guardian picks a free time for their child.
	 *
	 * A ConferenceSignup create naming the child and the slot. Portaliq
	 * stamps the guardian's reference into `guardianRef` and refuses a child
	 * that is not the guardian's (`crossRefs`). ConferenceSlotBookingStamp
	 * then checks the slot may be booked for that child and claims it under
	 * a lock, so two families never get the same time.
	 *
	 * @return array<string, mixed> The create action.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
	 */
	private function bookSlotAction(): array {
		return [
			'id' => 'bookConferenceSlot',
			'type' => 'create',
			'label' => 'Book a time',
			'register' => self::REGISTER,
			'schema' => 'conference-signup',
			'scopeField' => 'guardianRef',
			'scopeClaim' => 'guardianRef',
			'minTrust' => 'substantial',
			'fields' => [
				'learnerRef',
				'slotId',
				'notes',
			],
			'crossRefs' => ['learnerRef' => $this->childCrossRef()],
			'optionsProviders' => [
				'learnerRef' => $this->childOptions(),
				'slotId' => [
					'type' => 'collection',
					'register' => self::REGISTER,
					'schema' => 'conference-slot',
					'labelField' => 'slotLabel',
					'valueField' => 'id',
				],
			],
			'fieldConfigs' => [
				'learnerRef' => ['label' => 'Child', 'required' => true],
				'slotId' => ['label' => 'Time', 'required' => true],
				'notes' => ['label' => 'Anything the teacher should know beforehand'],
			],
			'submitLabel' => 'Book this time',
			'successMessage' => 'The time is yours. The teacher still acknowledges it; you see the status under your conference times.',
		];

	}//end bookSlotAction()

	/**
	 * The guardian cancels a time they booked, until the booking window
	 * closes. The server sets the state; ConferenceSlotBookingSync refuses a
	 * cancel after the window and offers the time to other families again.
	 *
	 * `rowWhen` (portaliq update-row-action-condition) shows the button only
	 * on a booked or acknowledged time, the states a parent may cancel. It
	 * only hides the button: the window, which the row cannot express, and
	 * every other refusal stay with ConferenceSlotBookingSync.
	 *
	 * @return array<string, mixed> The update action.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
	 * @spec openspec/changes/parent-row-actions-only-where-they-apply/specs/portal-contribution/spec.md#requirement-a-guardian-is-offered-a-cancel-only-on-a-time-that-can-still-be-cancelled
	 */
	private function cancelTimeAction(): array {
		return [
			'id' => 'cancelConferenceTime',
			'type' => 'update',
			'label' => 'Cancel this time',
			'register' => self::REGISTER,
			'schema' => 'conference-slot',
			'scopeField' => 'guardianRef',
			'scopeClaim' => 'guardianRef',
			'minTrust' => 'substantial',
			'fields' => ['lifecycle'],
			'set' => ['lifecycle' => 'cancelled'],
			'rowWhen' => [
				'field' => 'lifecycle',
				'in' => ['booked', 'acknowledged'],
			],
			'submitLabel' => 'Cancel this time',
			'successMessage' => 'The time is cancelled. You can book another free time while booking is open.',
		];

	}//end cancelTimeAction()


	/**
	 * The guardian asks for a parent-teacher conversation for their child,
	 * in a round where the school plans the times (preference booking).
	 *
	 * Portaliq stamps the guardian's own learniq reference into `guardianRef`
	 * (the scope claim) and refuses a child that is not the guardian's
	 * (`crossRefs`). ConferenceSignupPortalStamp then checks the round is open
	 * to that child, stamps the child's user id, the tenant and `submitted`,
	 * and fills the requested teachers from the child's group when the
	 * guardian names none.
	 *
	 * @return array<string, mixed> The create action.
	 *
	 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
	 */
	private function conferenceSignupAction(): array {
		return [
			'id' => 'createConferenceSignup',
			'type' => 'create',
			'label' => 'Ask for a parent-teacher conversation',
			'register' => self::REGISTER,
			'schema' => 'conference-signup',
			'scopeField' => 'guardianRef',
			'scopeClaim' => 'guardianRef',
			'minTrust' => 'substantial',
			'fields' => [
				'conferenceRoundId',
				'learnerRef',
				'notes',
			],
			'crossRefs' => ['learnerRef' => $this->childCrossRef()],
			'optionsProviders' => [
				'learnerRef' => $this->childOptions(),
				'conferenceRoundId' => [
					'type' => 'collection',
					'register' => self::REGISTER,
					'schema' => 'conference-round',
					'labelField' => 'name',
					'valueField' => 'id',
				],
			],
			'fieldConfigs' => [
				'conferenceRoundId' => ['label' => 'Conference round', 'required' => true],
				'learnerRef' => ['label' => 'Child', 'required' => true],
				'notes' => ['label' => 'Anything the teacher should know beforehand'],
			],
			'submitLabel' => 'Book',
			'successMessage' => 'Your booking is in. The school plans the times, and you see yours under your conference times.',
		];

	}//end conferenceSignupAction()

	/**
	 * The parent contribution with its pages (see pages()).
	 *
	 * @param array<string, mixed> $contribution The contribution, with collections and actions.
	 *
	 * @return array<string, mixed> The same contribution with `pages`.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
	 */
	public function withPages(array $contribution): array {
		$contribution['pages'] = $this->pages(collections: $contribution['collections'], actions: $contribution['actions']);

		return $contribution;

	}//end withPages()

	/**
	 * The parent pages: one per listable collection, built the way portaliq
	 * builds its default pages (the collection's create form, the table, the
	 * selected row), with one difference. Portaliq puts the FIRST create
	 * action of a schema on every page of that schema, and both conference
	 * forms create a `conference-signup`; so the free times page carries
	 * "Book a time" and the bookings page carries the request form of a
	 * round the school plans.
	 *
	 * Page ids are the collection ids, as portaliq's own, so the site's
	 * routes (`/mijn/learniq/<collection>`) stay the same.
	 *
	 * @param array<int, array<string, mixed>> $collections The parent collections.
	 * @param array<int, array<string, mixed>> $actions The parent actions.
	 *
	 * @return array<int, array<string, mixed>> The pages.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
	 */
	public function pages(array $collections, array $actions): array {
		$forms = [
			'parentConferenceFreeSlots' => 'bookConferenceSlot',
			'parentConferenceSignups' => 'createConferenceSignup',
		];
		$pages = [];
		foreach ($collections as $collection) {
			if (($collection['listable'] ?? true) !== true) {
				continue;
			}

			$id = (string)$collection['id'];
			$form = ($forms[$id] ?? $this->firstCreateFor(schema: (string)$collection['schema'], actions: $actions));
			$blocks = [];
			if ($form !== null) {
				$blocks[] = ['type' => 'action', 'action' => $form];
			}

			$blocks[] = ['type' => 'collection', 'collection' => $id];
			$blocks[] = ['type' => 'detail', 'collection' => $id];
			$pages[] = ['id' => $id, 'label' => (string)($collection['label'] ?? $id), 'blocks' => $blocks];
		}

		return $pages;

	}//end pages()

	/**
	 * The first create action for a schema, as portaliq picks it.
	 *
	 * @param string $schema The collection's schema.
	 * @param array<int, array<string, mixed>> $actions The actions.
	 *
	 * @return string|null The action id.
	 */
	private function firstCreateFor(string $schema, array $actions): ?string {
		foreach ($actions as $action) {
			if (($action['type'] ?? '') === 'create' && ($action['schema'] ?? '') === $schema) {
				return (string)$action['id'];
			}
		}

		return null;

	}//end firstCreateFor()

	/**
	 * The cross reference that proves a `learnerRef` names one of the
	 * guardian's own children: a direct read of `learner-profile` whose
	 * `guardianRefs` list holds the guardian's claim.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
	 */
	public function childCrossRef(): array {
		return [
			'register' => self::REGISTER,
			'schema' => 'learner-profile',
			'scopeField' => 'guardianRefs',
			'scopeClaim' => 'guardianRef',
			'required' => true,
		];

	}//end childCrossRef()

	/**
	 * A child picker over the guardian's own children.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
	 */
	public function childOptions(): array {
		return [
			'type' => 'collection',
			'register' => self::REGISTER,
			'schema' => 'learner-profile',
			'labelField' => 'givenName',
			'valueField' => 'id',
		];

	}//end childOptions()
}//end class
