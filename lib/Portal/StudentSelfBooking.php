<?php

/**
 * Learniq StudentSelfBooking
 *
 * What a pupil books herself on her portal: a place in the next lesson of
 * an optional-lesson offer of her own groups, such as a support lesson
 * ("Steunles wiskunde ... Voor morgen zijn er nog 6 plekken"), and the
 * sign-ups she made.
 *
 * The offers are read through a reverse join on her own active enrolments,
 * as her timetable is: an offer counts when one of its `eligibleCohortIds`
 * is a group she is enrolled in now, so a pupil only ever reads the offers
 * of her own school and year. An offer open to every learner names no group
 * and is therefore not listed on the portal. The offer's next date, room and
 * places left are readable copies the server keeps on the offer
 * (ElectiveOfferCopiesStamp); the lessons and the other pupils' sign-ups are
 * never read.
 *
 * The sign-up is an `endpoint-forward` to PortalElectiveController, a row
 * action on the offer: portaliq stamps her own `learnerRef` and the row's
 * id, and learniq writes the sign-up as her through every sign-up rule.
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
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The pupil's own bookings: optional lessons and her mentor talk.
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */
class StudentSelfBooking {

	private const REGISTER = 'learniq';

	/**
	 * Her live enrolments, whose groups say which offers are hers.
	 */
	private const OWN_GROUPS_VIA = [
		'register' => self::REGISTER,
		'schema' => 'enrolment',
		'scopeField' => 'learnerRef',
		'targetField' => 'cohortId',
		'match' => 'scopeField',
		'when' => ['field' => 'lifecycle', 'in' => ['active']],
	];

	private const ELECTIVE_OFFER_FIELDS = [
		'name',
		'description',
		'teacherName',
		'nextLessonStartsAt',
		'nextLessonEndsAt',
		'nextLessonLocation',
		'placesLeft',
		'placesLeftLine',
		'lifecycle',
	];

	/**
	 * The sign-up is offered on an open offer only, the one state that
	 * takes sign-ups (ElectiveSignUpRules refuses every other).
	 */
	public const SIGN_UP_ROW_WHEN = ['field' => 'lifecycle', 'in' => ['open']];

	private const ELECTIVE_OFFER_COLUMNS = [
		['field' => 'name', 'label' => 'Support lesson'],
		['field' => 'nextLessonStartsAt', 'label' => 'Next lesson', 'render' => 'datetime'],
		['field' => 'nextLessonLocation', 'label' => 'Room'],
		['field' => 'teacherName', 'label' => 'Teacher'],
		['field' => 'placesLeftLine', 'label' => 'Places'],
	];

	private const ELECTIVE_SIGN_UP_FIELDS = [
		'learnerRef',
		'offerId',
		'offerName',
		'lessonStartsAt',
		'status',
	];

	private const ELECTIVE_SIGN_UP_COLUMNS = [
		['field' => 'offerName', 'label' => 'Support lesson'],
		['field' => 'lessonStartsAt', 'label' => 'Date', 'render' => 'datetime'],
		['field' => 'status', 'label' => 'Status', 'valueLabels' => self::SIGN_UP_STATUS],
	];

	private const CONFERENCE_TIMES_FIELDS = [
		'conferenceRoundId',
		'teacherName',
		'startsAt',
		'endsAt',
		'location',
		'slotLabel',
		'lifecycle',
	];

	private const CONFERENCE_TIMES_COLUMNS = [
		['field' => 'startsAt', 'label' => 'Starts', 'render' => 'datetime'],
		['field' => 'teacherName', 'label' => 'With'],
		['field' => 'location', 'label' => 'Where'],
	];

	private const CONFERENCE_SIGNUPS_FIELDS = [
		'learnerRef',
		'slotLabel',
		'teacherName',
		'startsAt',
		'endsAt',
		'bookedBy',
		'lifecycle',
		'declineNote',
	];

	private const CONFERENCE_SIGNUPS_COLUMNS = [
		['field' => 'slotLabel', 'label' => 'Time'],
		['field' => 'lifecycle', 'label' => 'Status', 'valueLabels' => PortalValueLabels::SIGNUP_STATUS],
		['field' => 'declineNote', 'label' => 'Reason for declining'],
	];

	/**
	 * Her invitations to a round she books herself, still to book: one row
	 * per round (ConferenceInvitations), open while the round is open and she
	 * has no time yet. Not in the menu: the overview's task opens the page
	 * with the times (D-5: the invitation links to the booking, it does not
	 * carry the times).
	 */
	private const CONFERENCE_INVITATIONS = [
		'id' => 'studentConferenceInvitations',
		'register' => self::REGISTER,
		'schema' => 'conference-invitation',
		'scopeField' => 'learnerRef',
		'scopeClaim' => 'learnerRef',
		'filter' => ['status' => 'open', 'pupilMayBook' => true],
		'label' => 'Talks still to book',
		'listable' => false,
		'minTrust' => 'low',
		'fields' => ['learnerRef', 'conferenceRoundId', 'roundName', 'bookingClosesAt', 'status'],
		'columns' => [
			['field' => 'roundName', 'label' => 'Conversation'],
			['field' => 'bookingClosesAt', 'label' => 'Book before', 'render' => 'date'],
		],
	];

	/**
	 * The page her task opens.
	 */
	public const PICK_A_TIME_PAGE = 'studentPickATime';

	private const BOOK_TIME_OPTIONS = [
		'type' => 'collection',
		'register' => self::REGISTER,
		'schema' => 'conference-slot',
		'labelField' => 'slotLabel',
		'valueField' => 'id',
	];

	private const BOOK_TIME_FIELD_CONFIGS = [
		'slotId' => ['label' => 'Time', 'required' => true],
		'notes' => ['label' => 'Anything your mentor should know beforehand'],
	];

	/**
	 * The words for a sign-up's status.
	 */
	public const SIGN_UP_STATUS = [
		'signed-up' => 'Signed up',
		'placed' => 'Placed by the school',
		'withdrawn' => 'Withdrawn',
	];

	/**
	 * The pupil's optional-lesson collections: the open offers of her
	 * groups, then her own sign-ups.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function electiveCollections(): array {
		return [
			[
				'id' => 'studentElectiveOffers',
				'register' => self::REGISTER,
				'schema' => 'elective-offer',
				'scopeField' => 'eligibleCohortIds',
				'scopeClaim' => 'learnerRef',
				'via' => self::OWN_GROUPS_VIA,
				'filter' => ['lifecycle' => 'open'],
				'label' => 'Support lessons',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::ELECTIVE_OFFER_FIELDS,
				'columns' => self::ELECTIVE_OFFER_COLUMNS,
				'rowActions' => ['signUpForElective'],
			],
			[
				'id' => 'studentElectiveSignUps',
				'register' => self::REGISTER,
				'schema' => 'elective-sign-up',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My support lessons',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::ELECTIVE_SIGN_UP_FIELDS,
				'columns' => self::ELECTIVE_SIGN_UP_COLUMNS,
			],
		];
	}//end electiveCollections()

	/**
	 * She signs up for the next lesson of an offer: a row action on the
	 * offer, forwarded to PortalElectiveController.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function signUpForElectiveAction(): array {
		return [
			'id' => 'signUpForElective',
			'type' => 'endpoint-forward',
			'label' => 'Sign up for the next lesson',
			'endpoint' => '/apps/learniq/api/portal/electives/sign-up',
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => ['offerId'],
			'subjectField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'rowField' => 'offerId',
			'rowWhen' => self::SIGN_UP_ROW_WHEN,
		];
	}//end signUpForElectiveAction()
	/**
	 * The pupil's talk collections: the free times she may book herself, and
	 * her talks.
	 *
	 * The free times are those whose `eligibleLearnerRefs` (the invited pupils
	 * of that teacher's own groups, ConferenceFreeSlotGenerator) hold her
	 * own uuid, still `free`, in a round that lets pupils book (the slot's
	 * copy of `pupilMayBook`): a parent evening never shows. They come before
	 * her talks, because portaliq fills the booking form's time picker from
	 * the first collection over `conference-slot`. Her talks are every
	 * booking about her, whoever made it.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-may-book-her-own-mentor-talk-when-the-round-allows-it
	 */
	public function conferenceCollections(): array {
		return [
			self::CONFERENCE_INVITATIONS,
			[
				'id' => 'studentConferenceTimes',
				'register' => self::REGISTER,
				'schema' => 'conference-slot',
				'scopeField' => 'eligibleLearnerRefs',
				'scopeClaim' => 'learnerRef',
				'filter' => ['lifecycle' => 'free', 'pupilMayBook' => true],
				'label' => 'Times for your mentor talk',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::CONFERENCE_TIMES_FIELDS,
				'columns' => self::CONFERENCE_TIMES_COLUMNS,
			],
			[
				'id' => 'studentConferenceSignups',
				'register' => self::REGISTER,
				'schema' => 'conference-signup',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My mentor talks',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::CONFERENCE_SIGNUPS_FIELDS,
				'columns' => self::CONFERENCE_SIGNUPS_COLUMNS,
			],
		];
	}//end conferenceCollections()

	/**
	 * She books one of those times for herself: a ConferenceSignup create
	 * whose `learnerRef` portaliq stamps from her own claim, so she can only
	 * ever book for herself. ConferenceSlotBookingStamp then refuses unless
	 * the round lets pupils book and claims the time under the same lock as
	 * a guardian's booking.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-may-book-her-own-mentor-talk-when-the-round-allows-it
	 */
	public function bookConferenceSlotAction(): array {
		return [
			'id' => 'bookConferenceSlotAsPupil',
			'type' => 'create',
			'label' => 'Choose a time',
			'register' => self::REGISTER,
			'schema' => 'conference-signup',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'minTrust' => 'low',
			'fields' => ['slotId', 'notes'],
			'requiredFields' => ['slotId'],
			'optionsProviders' => ['slotId' => self::BOOK_TIME_OPTIONS],
			'fieldConfigs' => self::BOOK_TIME_FIELD_CONFIGS,
			'submitLabel' => 'Book this time',
			'successMessage' => 'The time is yours. Your parents get a message about it.',
		];
	}//end bookConferenceSlotAction()
	/**
	 * "Kies een tijd voor het mentorgesprek": one task per invitation still
	 * to book, on her overview. Its button opens the booking page; the
	 * times themselves are never part of the task.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/parent-conferences/spec.md#requirement-a-conference-round-says-whether-pupils-may-book
	 */
	public function invitationTasksBlock(): array {
		return [
			'type' => 'tasks',
			'label' => 'Choose a time',
			'display' => 'highlight',
			'collection' => 'studentConferenceInvitations',
			'dueField' => 'bookingClosesAt',
			'titleFields' => ['roundName'],
			'titleTemplate' => 'Choose a time for your mentor talk',
			'buttonLabel' => 'Choose a time',
			'dueInLine' => true,
		];
	}//end invitationTasksBlock()

	/**
	 * The page the task opens: the invitation, the booking form and the free
	 * times. Out of the menu; portaliq opens a task on the page that shows
	 * its collection, with the row selected.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/parent-conferences/spec.md#requirement-a-conference-round-says-whether-pupils-may-book
	 */
	public function pickATimePage(): array {
		return [
			'id' => self::PICK_A_TIME_PAGE,
			'label' => 'Choose a time',
			'menu' => false,
			'blocks' => [
				['type' => 'detail', 'collection' => 'studentConferenceInvitations'],
				['type' => 'action', 'action' => 'bookConferenceSlotAsPupil'],
				['type' => 'collection', 'collection' => 'studentConferenceTimes'],
			],
		];
	}//end pickATimePage()
}//end class
