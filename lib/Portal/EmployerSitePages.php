<?php

/**
 * Learniq EmployerSitePages
 *
 * The employer's portal: a company that sends its people to the institute's
 * courses (the Warmtepompacademie board: Linda Jansen of Jansen
 * Installatietechniek BV). She reads her company's bookings with their days,
 * people and status, what still waits for her, and her employees; she books
 * places, names a participant for a place and supplies a missing birth date
 * (employer-portal-audience).
 *
 * Every collection is scoped by the `organisationRef` claim: the uuid of the
 * company's `client-organisation` row, written on her portal account when the
 * institute invites her (`occ learniq:portal:invite-employer`). The rows carry
 * that key themselves (a booking's `organisationRef`, an enrolment's and a
 * profile's readable copy), so every read is a direct match and none needs a
 * join. The booking form's editions are scoped by a second claim,
 * `editionLocationRef`: the institute location whose editions the company may
 * book.
 *
 * Plain data, no I/O, like the other audiences' classes. The booking steps
 * come from EmployerBookingSteps through the provider.
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-reads-only-her-own-companys-people-and-bookings
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the employer's collections, actions and pages.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-reads-only-her-own-companys-people-and-bookings
 */
class EmployerSitePages {

	/**
	 * The audience.
	 */
	public const AUDIENCE = 'employer';

	/**
	 * The claim every employer collection is scoped by.
	 */
	public const CLAIM = 'organisationRef';

	/**
	 * The claim the booking form's editions are scoped by.
	 */
	public const LOCATION_CLAIM = 'editionLocationRef';

	/**
	 * The provider method that answers a booking's steps.
	 */
	public const STEPS_PROVIDER = 'employerBookingSteps';

	private const REGISTER = 'learniq';

	private const GROUP_ACADEMY = 'My academy';

	private const GROUP_COURSES = 'Courses';

	/**
	 * How a booking's status reads, and its tone.
	 *
	 * @var array<string, string>
	 */
	public const BOOKING_STATUS = [
		'waiting-for-you' => 'Waiting for you',
		'received' => 'Received',
		'confirmed' => 'Confirmed',
		'completed' => 'Completed',
		'cancelled' => 'Cancelled',
	];

	private const BOOKING_TONES = [
		'waiting-for-you' => 'warning',
		'received' => 'neutral',
		'confirmed' => 'success',
		'completed' => 'neutral',
		'cancelled' => 'neutral',
	];

	/**
	 * How a participant's details read.
	 *
	 * @var array<string, string>
	 */
	public const DETAILS_STATUS = [
		'complete' => 'Details complete',
		'birth-date-missing' => 'Birth date missing',
	];

	/**
	 * The employer's manifest.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-reads-only-her-own-companys-people-and-bookings
	 */
	public function contribution(): array {
		$collections = $this->collections();
		$actions = $this->actions();

		return [
			// She reads "Mijn academie" over these sections, not the app's name.
			'label' => 'My academy',
			'collections' => $collections,
			'actions' => $actions,
			'pages' => $this->pages(),
			'notifications' => [],
		];
	}//end contribution()

	/**
	 * Every employer collection. The order matters where two read one
	 * schema: a form's choice list reads the first collection over its
	 * schema, so the coming bookings come before all bookings, and the
	 * participants still missing a birth date before all participants.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-reads-only-her-own-companys-people-and-bookings
	 */
	public function collections(): array {
		$statusConfig = ['employerStatus' => ['valueLabels' => self::BOOKING_STATUS]];

		return [
			$this->direct(
				id: 'employerComingBookings',
				schema: 'course-booking',
				label: 'Coming course days',
				fields: $this->bookingFields(),
				extra: [
					'filter' => ['upcoming' => true],
					'defaultSort' => ['field' => 'firstDay', 'direction' => 'asc'],
					'fieldConfigs' => $statusConfig,
				]
			),
			$this->direct(
				id: 'employerBookings',
				schema: 'course-booking',
				label: 'Bookings',
				fields: $this->bookingFields(),
				extra: [
					// A booking is followed like a case: it has steps (portaliq REQ-SMO-022).
					'kind' => 'cases',
					'steps' => ['label' => 'Where does this booking stand?', 'provider' => self::STEPS_PROVIDER],
					'defaultSort' => ['field' => 'firstDay', 'direction' => 'desc'],
					'fieldConfigs' => $statusConfig,
					'columns' => [
						['field' => 'firstDay', 'label' => 'First day', 'render' => 'date'],
						['field' => 'courseName', 'label' => 'Course name'],
						['field' => 'participantNames', 'label' => 'Participants'],
						['field' => 'employerStatus', 'label' => 'Status', 'valueLabels' => self::BOOKING_STATUS],
					],
				]
			),
			$this->direct(
				id: 'employerOpenTasks',
				schema: 'enrolment',
				label: 'Still to do',
				fields: $this->participantFields(),
				extra: ['filter' => ['detailsStatus' => 'birth-date-missing']]
			),
			$this->direct(
				id: 'employerParticipants',
				schema: 'enrolment',
				label: 'Participants',
				fields: $this->participantFields(),
				extra: [
					'fieldConfigs' => ['detailsStatus' => ['valueLabels' => self::DETAILS_STATUS]],
					'columns' => [
						['field' => 'learnerName', 'label' => 'Participant'],
						['field' => 'certificateLine', 'label' => 'Certificate'],
						['field' => 'detailsStatus', 'label' => 'Details', 'valueLabels' => self::DETAILS_STATUS],
					],
				]
			),
			// Names only: never the birth date, the address or anything the
			// institute keeps for itself.
			$this->direct(
				id: 'employerEmployees',
				schema: 'learner-profile',
				label: 'Employees',
				fields: ['organisationRef', 'fullName', 'givenName', 'familyName', 'department', 'lifecycle'],
				extra: [
					'filter' => ['lifecycle' => 'active'],
					'defaultSort' => ['field' => 'familyName', 'direction' => 'asc'],
					'columns' => [
						['field' => 'fullName', 'label' => 'Name'],
						['field' => 'department', 'label' => 'Department'],
					],
				]
			),
			[
				'id' => 'employerEditions',
				'register' => self::REGISTER,
				'schema' => 'cohort',
				'scopeField' => 'locationId',
				'scopeClaim' => self::LOCATION_CLAIM,
				'label' => 'Course dates you can book',
				'listable' => false,
				'minTrust' => 'low',
				'filter' => ['lifecycle' => 'planned'],
				'fields' => ['name', 'courseId', 'period', 'locationId', 'lifecycle'],
			],
		];
	}//end collections()

	/**
	 * What she may do: book places, name a participant, supply a birth date.
	 * Each goes through learniq's own endpoint with her company stamped from
	 * the claim; `schema` is declared only so portaliq shapes the inputs.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function actions(): array {
		return [
			$this->endpoint(
				id: 'enrolEmployees',
				path: 'bookings',
				schema: 'course-booking',
				label: 'Book places',
				fields: ['cohortId', 'participantCount'],
				extra: [
					'optionsProviders' => ['cohortId' => $this->options(schema: 'cohort', label: 'name', value: 'id')],
					'fieldConfigs' => [
						'cohortId' => ['label' => 'Course and date', 'required' => true],
						// Portaliq's count stepper; an older portaliq drops the widget and asks for a number.
						'participantCount' => [
							'label' => 'Number of participants',
							'required' => true,
							'widget' => 'count',
							'min' => 1,
							'max' => 12,
							'unit' => ['one' => 'participant', 'other' => 'participants'],
							'help' => 'You fill in the names in the next step. Six participants or more? Ask for a day of your own.',
						],
					],
					'requiredFields' => ['cohortId', 'participantCount'],
					'submitLabel' => 'Book these places',
					'successMessage' => 'Your booking is received. Now fill in who takes each place.',
				]
			),
			$this->endpoint(
				id: 'addBookingParticipant',
				path: 'participants',
				schema: 'enrolment',
				label: 'Fill in a participant',
				fields: ['bookingRef', 'learnerRef'],
				extra: [
					'optionsProviders' => [
						'bookingRef' => $this->options(schema: 'course-booking', label: 'bookingLabel', value: 'id'),
						'learnerRef' => $this->options(schema: 'learner-profile', label: 'fullName', value: 'id'),
					],
					'fieldConfigs' => [
						'bookingRef' => ['label' => 'Booking', 'required' => true],
						'learnerRef' => ['label' => 'Employee', 'required' => true],
					],
					'requiredFields' => ['bookingRef', 'learnerRef'],
					'submitLabel' => 'Put on the booking',
					'successMessage' => 'The employee has a place on the booking.',
				]
			),
			$this->endpoint(
				id: 'supplyBirthDate',
				path: 'birth-date',
				schema: 'learner-profile',
				label: 'Fill in a birth date',
				fields: ['learnerRef', 'birthDate'],
				extra: [
					// The participants still missing a birth date: the first enrolment collection.
					'optionsProviders' => ['learnerRef' => $this->options(schema: 'enrolment', label: 'learnerName', value: 'learnerRef')],
					'fieldConfigs' => [
						'learnerRef' => ['label' => 'Participant', 'required' => true],
						'birthDate' => [
							'label' => 'Birth date',
							'required' => true,
							'help' => 'The exam institution uses the birth date to register the participant. We keep it only with this participant.',
						],
					],
					'requiredFields' => ['learnerRef', 'birthDate'],
					'submitLabel' => 'Save',
					'successMessage' => 'The birth date is saved. The participant can take the exam.',
				]
			),
		];
	}//end actions()

	/**
	 * Her pages: the overview, the bookings with the open one in detail, her
	 * employees, and the open tasks (off the menu, reached from the overview).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-lands-on-what-waits-for-her
	 */
	public function pages(): array {
		return [
			$this->overviewPage(),
			$this->bookingsPage(),
			[
				'id' => 'employerEmployees',
				'label' => 'Employees',
				'icon' => 'AccountGroupOutline',
				'group' => self::GROUP_COURSES,
				'blocks' => [
					['type' => 'collection', 'collection' => 'employerEmployees'],
					['type' => 'action', 'action' => 'addBookingParticipant'],
				],
			],
			[
				'id' => 'employerOpenTasks',
				'label' => 'Still to do',
				'menu' => false,
				'blocks' => [
					[
						'type' => 'collection',
						'collection' => 'employerOpenTasks',
						'display' => 'rows',
						'titleFields' => ['openTask'],
						'subtitleField' => 'openTaskNote',
						'dateField' => 'openTaskDueAt',
					],
					['type' => 'action', 'action' => 'supplyBirthDate'],
				],
			],
		];
	}//end pages()

	/**
	 * The overview (board MijnOverzicht): the greeting with the booking
	 * button, the one thing that waits for her, and the coming course days.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-lands-on-what-waits-for-her
	 */
	private function overviewPage(): array {
		return [
			'id' => 'employerOverview',
			'label' => 'Overview',
			'icon' => 'ViewDashboard',
			'group' => self::GROUP_ACADEMY,
			'home' => true,
			'blocks' => [
				['type' => 'greeting', 'label' => 'Book places', 'action' => 'enrolEmployees'],
				[
					'type' => 'tasks',
					'label' => 'First this',
					'display' => 'highlight',
					'eyebrow' => 'First this',
					'collection' => 'employerOpenTasks',
					'titleFields' => ['openTask'],
					'subtitleFields' => ['openTaskNote'],
					'dueField' => 'openTaskDueAt',
					'buttonLabel' => 'Fill in the birth date',
				],
				[
					'type' => 'collection',
					'collection' => 'employerComingBookings',
					'display' => 'rows',
					'dateField' => 'firstDay',
					'titleFields' => ['courseName'],
					'subtitleField' => 'participantNames',
					'statusField' => 'employerStatus',
					'statusTones' => self::BOOKING_TONES,
					'statusNoteField' => 'statusNote',
					'limit' => 3,
					'sort' => ['field' => 'firstDay', 'direction' => 'asc'],
				],
				['type' => 'cta', 'page' => 'employerBookings', 'label' => 'All bookings'],
			],
		];
	}//end overviewPage()

	/**
	 * The bookings (boards MijnLijst and Detail): every booking as a dated
	 * row, then the open booking (chosen with the switcher) with its steps,
	 * its participants and its course day, and the two forms.
	 *
	 * Portaliq cannot link a row to a record page, so the list and the
	 * detail share one page, the way the guardian's child page does.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	private function bookingsPage(): array {
		return [
			'id' => 'employerBookings',
			'label' => 'Bookings',
			'icon' => 'CalendarAccountOutline',
			'group' => self::GROUP_COURSES,
			'record' => ['collection' => 'employerBookings', 'titleFields' => ['courseName']],
			'blocks' => [
				[
					'type' => 'collection',
					'collection' => 'employerBookings',
					'display' => 'rows',
					'dateField' => 'firstDay',
					'titleFields' => ['courseName'],
					'subtitleField' => 'participantNames',
					'statusField' => 'employerStatus',
					'statusTones' => self::BOOKING_TONES,
					'statusNoteField' => 'statusNote',
					'sort' => ['field' => 'firstDay', 'direction' => 'asc'],
				],
				['type' => 'steps', 'collection' => 'employerBookings', 'label' => 'Where does this booking stand?'],
				['type' => 'collection', 'collection' => 'employerParticipants', 'recordField' => 'bookingRef'],
				['type' => 'detail', 'collection' => 'employerBookings'],
				['type' => 'action', 'action' => 'supplyBirthDate'],
				['type' => 'action', 'action' => 'addBookingParticipant'],
				['type' => 'cta', 'action' => 'enrolEmployees', 'label' => 'New booking'],
			],
		];
	}//end bookingsPage()

	/**
	 * What a booking row shows her.
	 *
	 * @return array<int, string>
	 */
	private function bookingFields(): array {
		return [
			'organisationRef',
			'bookingNumber',
			'bookingLabel',
			'courseName',
			'firstDay',
			'dayLabel',
			'timeLabel',
			'placeLabel',
			'trainerName',
			'participantCount',
			'participantNames',
			'missingDetailsCount',
			'employerStatus',
			'statusNote',
			'detailsDueAt',
			'requestedAt',
			'upcoming',
			'lifecycle',
		];
	}//end bookingFields()

	/**
	 * What a participant row shows her: never the birth date itself.
	 *
	 * @return array<int, string>
	 */
	private function participantFields(): array {
		return [
			'organisationRef',
			'bookingRef',
			'learnerRef',
			'learnerName',
			'courseName',
			'detailsStatus',
			'openTask',
			'openTaskNote',
			'openTaskDueAt',
			'certificateLine',
			'lifecycle',
		];
	}//end participantFields()

	/**
	 * A collection matched directly on her company.
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
				// An employer is invited by the institute and signs in from her
				// company, like the workplace trainer; her rows are already narrowed
				// to her company.
				'minTrust' => 'low',
				'fields' => $fields,
			],
			$extra
		);
	}//end direct()

	/**
	 * A forward to learniq's employer endpoint with her company stamped.
	 *
	 * @param string               $id     The action id.
	 * @param string               $path   The endpoint under /api/portal/employer/.
	 * @param string               $schema The schema whose properties shape the inputs.
	 * @param string               $label  The label.
	 * @param array<int, string>   $fields The fields the form sends.
	 * @param array<string, mixed> $extra  More keys.
	 *
	 * @return array<string, mixed>
	 */
	private function endpoint(string $id, string $path, string $schema, string $label, array $fields, array $extra): array {
		return array_merge(
			[
				'id' => $id,
				'type' => 'endpoint-forward',
				'label' => $label,
				'endpoint' => '/apps/learniq/api/portal/employer/' . $path,
				'method' => 'POST',
				'register' => self::REGISTER,
				'schema' => $schema,
				'minTrust' => 'low',
				'subjectField' => self::CLAIM,
				'scopeClaim' => self::CLAIM,
				'fields' => $fields,
			],
			$extra
		);
	}//end endpoint()

	/**
	 * A choice list filled from her own collection over a schema.
	 *
	 * @param string $schema The schema slug.
	 * @param string $label  The field a choice reads.
	 * @param string $value  The field a choice sends.
	 *
	 * @return array<string, string>
	 */
	private function options(string $schema, string $label, string $value): array {
		return [
			'type' => 'collection',
			'register' => self::REGISTER,
			'schema' => $schema,
			'labelField' => $label,
			'valueField' => $value,
		];
	}//end options()
}//end class
