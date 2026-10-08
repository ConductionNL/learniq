<?php

/**
 * Learniq ParticipantSitePages
 *
 * The course participant's portal at a training institute (the
 * Warmtepompacademie board, MobielHome and MobielDetail: Tom Verbeek): his
 * next course day with when, where and who teaches it, his certificates with
 * their expiry, and his later course days (participant-portal).
 *
 * A participant is an adult learner whose employer booked him; his portal is
 * not the pupil's (no homework, grades or absence), so he has an audience of
 * his own, `participant`. Every collection is scoped by the `learnerRef`
 * claim, the same claim a pupil's account carries: his enrolments directly,
 * his certificates on `learnerId` (the learner profile's uuid).
 *
 * His course day is read from his own enrolment: EmployerBookingProjection
 * copies the booking's day, time, place and trainer onto each participant's
 * enrolment, so no join is needed.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/participant-portal/specs/portal-contribution/spec.md#requirement-a-participant-lands-on-his-next-course-day
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the participant's collections and pages.
 *
 * @spec openspec/changes/participant-portal/specs/portal-contribution/spec.md#requirement-a-participant-lands-on-his-next-course-day
 */
class ParticipantSitePages {

	/**
	 * The audience.
	 */
	public const AUDIENCE = 'participant';

	/**
	 * The claim every participant collection is scoped by.
	 */
	public const CLAIM = 'learnerRef';

	private const REGISTER = 'learniq';

	private const GROUP = 'My academy';

	/**
	 * The participant's manifest.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/participant-portal/specs/portal-contribution/spec.md#requirement-a-participant-lands-on-his-next-course-day
	 */
	public function contribution(): array {
		return [
			'label' => 'My academy',
			'collections' => $this->collections(),
			'actions' => [],
			'pages' => $this->pages(),
			'notifications' => [],
		];
	}//end contribution()

	/**
	 * His coming course days, all his course days, and his certificates.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/participant-portal/specs/portal-contribution/spec.md#requirement-a-participant-lands-on-his-next-course-day
	 */
	public function collections(): array {
		$dayFields = ['learnerRef', 'courseName', 'firstDay', 'dayLabel', 'timeLabel', 'placeLabel', 'trainerName', 'upcoming', 'lifecycle'];
		$dayColumns = [
			['field' => 'firstDay', 'label' => 'First day', 'render' => 'date'],
			['field' => 'courseName', 'label' => 'Course name'],
			['field' => 'dayLabel', 'label' => 'Days'],
			['field' => 'timeLabel', 'label' => 'Time'],
			['field' => 'placeLabel', 'label' => 'Place'],
			['field' => 'trainerName', 'label' => 'Trainer'],
		];

		return [
			$this->direct(
				id: 'participantComingDays',
				schema: 'enrolment',
				label: 'Your next course day',
				fields: $dayFields,
				extra: [
					'filter' => ['upcoming' => true],
					'defaultSort' => ['field' => 'firstDay', 'direction' => 'asc'],
					'columns' => $dayColumns,
				]
			),
			$this->direct(
				id: 'participantCourseDays',
				schema: 'enrolment',
				label: 'My course days',
				fields: $dayFields,
				extra: ['defaultSort' => ['field' => 'firstDay', 'direction' => 'desc'], 'columns' => $dayColumns]
			),
			$this->direct(
				id: 'participantCertificates',
				schema: 'credential',
				label: 'My certificates',
				fields: [
					'learnerId',
					'courseName',
					'kind',
					'validUntilLabel',
					'expiresAt',
					'expiryStatus',
					'expiryLabel',
					'renewalLine',
					'verificationUrl',
					'lifecycle',
				],
				extra: [
					// A certificate names its holder by the profile uuid in `learnerId`.
					'scopeField' => 'learnerId',
					'filter' => ['lifecycle' => 'issued', 'kind' => 'certificate'],
					'defaultSort' => ['field' => 'expiresAt', 'direction' => 'asc'],
					'fieldConfigs' => ['expiryStatus' => ['valueLabels' => EmployerSitePages::EXPIRY_STATUS]],
					'columns' => [
						['field' => 'courseName', 'label' => 'Certificate'],
						['field' => 'expiresAt', 'label' => 'Valid until', 'render' => 'date'],
						['field' => 'expiryLabel', 'label' => 'Status'],
						['field' => 'verificationUrl', 'label' => 'Check or download', 'render' => 'link'],
					],
				]
			),
		];
	}//end collections()

	/**
	 * His overview (board MobielHome), his course days (MobielDetail) and his certificates.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/participant-portal/specs/portal-contribution/spec.md#requirement-a-participant-lands-on-his-next-course-day
	 */
	public function pages(): array {
		return [
			[
				'id' => 'participantOverview',
				'label' => 'Overview',
				'icon' => 'ViewDashboard',
				'group' => self::GROUP,
				'home' => true,
				'blocks' => [
					['type' => 'greeting', 'label' => 'My course days', 'page' => 'participantCourseDays'],
					[
						'type' => 'tasks',
						'label' => 'Your next course day',
						'display' => 'highlight',
						'eyebrow' => 'Your next course day',
						'collection' => 'participantComingDays',
						'titleFields' => ['courseName'],
						'subtitleFields' => ['dayLabel', 'timeLabel', 'placeLabel'],
						'dueField' => 'firstDay',
						'buttonLabel' => 'Everything about this day',
						// Only the next one; the rest is "Daarna" (REPORT-2, item 10).
						'limit' => 1,
					],
					[
						'type' => 'collection',
						'collection' => 'participantCertificates',
						'display' => 'rows',
						'dateField' => 'expiresAt',
						'titleFields' => ['courseName'],
						'subtitleField' => 'validUntilLabel',
						'quoteField' => 'renewalLine',
						'statusField' => 'expiryStatus',
						'statusTones' => ['valid' => 'success', 'none' => 'success', 'expiring' => 'warning', 'expiring-soon' => 'warning', 'expired' => 'error'],
						'statusNoteField' => 'expiryLabel',
						'limit' => 3,
					],
					[
						'type' => 'collection',
						// "Daarna": the days after the next one. `skip` leaves out the
						// next day the highlight above shows (portaliq: requested).
						'label' => 'After that',
						'skip' => 1,
						'collection' => 'participantComingDays',
						'display' => 'rows',
						'dateField' => 'firstDay',
						'titleFields' => ['courseName'],
						'subtitleField' => 'dayLabel',
						'limit' => 3,
						'sort' => ['field' => 'firstDay', 'direction' => 'asc'],
					],
				],
			],
			[
				'id' => 'participantCourseDays',
				'label' => 'My course days',
				'icon' => 'CalendarMonth',
				'group' => self::GROUP,
				'blocks' => [
					['type' => 'collection', 'collection' => 'participantCourseDays'],
					['type' => 'detail', 'collection' => 'participantCourseDays'],
				],
			],
			[
				'id' => 'participantCertificates',
				'label' => 'My certificates',
				'icon' => 'CertificateOutline',
				'group' => self::GROUP,
				'blocks' => [['type' => 'collection', 'collection' => 'participantCertificates']],
			],
		];
	}//end pages()

	/**
	 * A collection matched directly on his own profile.
	 *
	 * @param string               $id     The collection id.
	 * @param string               $schema The schema slug.
	 * @param string               $label  The heading.
	 * @param array<int, string>   $fields The projected fields.
	 * @param array<string, mixed> $extra  More keys.
	 *
	 * @return array<string, mixed>
	 */
	private function direct(string $id, string $schema, string $label, array $fields, array $extra=[]): array {
		return array_merge(
			[
				'id' => $id,
				'register' => self::REGISTER,
				'schema' => $schema,
				'scopeField' => self::CLAIM,
				'scopeClaim' => self::CLAIM,
				'label' => $label,
				'listable' => true,
				'minTrust' => 'low',
				'fields' => $fields,
			],
			$extra
		);
	}//end direct()
}//end class
