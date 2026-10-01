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
	 * The guardian's parent-teacher conference collections: the rounds open
	 * to one of their children, their bookings and the scheduled times.
	 *
	 * All three go through the same reverse `via` join as every parent read.
	 * A round is matched on `invitedLearnerRefs`, which the round's
	 * `send-invitations` transition fills (ConferenceInvitationAction), and
	 * only a round in `booking-open` is listed.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>> Parent conference collections.
	 *
	 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
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
				],
				'columns' => [
					['field' => 'name', 'label' => 'Conference round'],
					['field' => 'bookingClosesAt', 'label' => 'Book before'],
					['field' => 'slotDurationMinutes', 'label' => 'Minutes per conversation'],
				],
			],
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
				],
				'columns' => [
					['field' => 'requestedTeacherIds', 'label' => 'With'],
					['field' => 'notes', 'label' => 'Your note'],
					['field' => 'lifecycle', 'label' => 'Status'],
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
				],
				'columns' => [
					['field' => 'startsAt', 'label' => 'Starts'],
					['field' => 'endsAt', 'label' => 'Ends'],
					['field' => 'teacherId', 'label' => 'With'],
					['field' => 'location', 'label' => 'Where'],
					['field' => 'lifecycle', 'label' => 'Status'],
				],
			],
		];

	}//end conferenceCollections()

	/**
	 * The guardian books a parent-teacher conversation for their child.
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
	public function conferenceSignupAction(): array {
		return [
			'id' => 'createConferenceSignup',
			'type' => 'create',
			'label' => 'Book a parent-teacher conversation',
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
