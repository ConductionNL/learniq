<?php

/**
 * Learniq StudentSelfBooking unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\StudentSelfBooking;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pupil's support-lesson collections and her sign-up action.
 */
#[CoversClass(StudentSelfBooking::class)]
class StudentSelfBookingTest extends TestCase {

	/**
	 * The offers are her own groups' open offers, reached through her live
	 * enrolments; only the offer's own words and copies are projected, never
	 * the lessons, the groups or other pupils' sign-ups.
	 *
	 * @return void
	 */
	public function testTheOffersAreHerGroupsOpenOffers(): void {
		$collections = array_column((new StudentSelfBooking())->electiveCollections(), null, 'id');
		$offers = $collections['studentElectiveOffers'];

		self::assertSame(['elective-offer', 'eligibleCohortIds', 'learnerRef'], [$offers['schema'], $offers['scopeField'], $offers['scopeClaim']]);
		self::assertSame(
			['register' => 'learniq', 'schema' => 'enrolment', 'scopeField' => 'learnerRef', 'targetField' => 'cohortId', 'match' => 'scopeField', 'when' => ['field' => 'lifecycle', 'in' => ['active']]],
			$offers['via']
		);
		self::assertSame(['lifecycle' => 'open'], $offers['filter']);
		self::assertSame([], array_intersect(['sessionIds', 'timetableSessionRefs', 'eligibleCohortIds', 'tenant_id'], $offers['fields']));
		self::assertContains('placesLeftLine', $offers['fields']);
		self::assertSame(['signUpForElective'], $offers['rowActions']);

		$mine = $collections['studentElectiveSignUps'];
		self::assertSame(['elective-sign-up', 'learnerRef', 'learnerRef'], [$mine['schema'], $mine['scopeField'], $mine['scopeClaim']]);
		self::assertNotContains('learnerId', $mine['fields']);
		self::assertNotContains('madeBy', $mine['fields']);
	}//end testTheOffersAreHerGroupsOpenOffers()

	/**
	 * The sign-up forwards only the offer of the row, with her own
	 * learnerRef stamped by portaliq, on an open offer only.
	 *
	 * @return void
	 */
	public function testTheSignUpForwardsTheRowsOffer(): void {
		$action = (new StudentSelfBooking())->signUpForElectiveAction();

		self::assertSame('endpoint-forward', $action['type']);
		self::assertSame('/apps/learniq/api/portal/electives/sign-up', $action['endpoint']);
		self::assertSame(['offerId'], $action['fields']);
		self::assertSame(['learnerRef', 'learnerRef', 'offerId'], [$action['subjectField'], $action['scopeClaim'], $action['rowField']]);
		self::assertSame(['field' => 'lifecycle', 'in' => ['open']], $action['rowWhen']);
	}//end testTheSignUpForwardsTheRowsOffer()

	/**
	 * Her free times are those she may book herself in a round that lets
	 * pupils book, listed before her talks so the time picker reads them;
	 * the booking stamps her own learnerRef and asks only for a time.
	 *
	 * @return void
	 */
	public function testSheBooksHerOwnTimeFromTheTimesSheMayBook(): void {
		$booking = new StudentSelfBooking();
		[$invitations, $times, $talks] = $booking->conferenceCollections();
		self::assertSame(['studentConferenceInvitations', 'conference-invitation', 'learnerRef'], [$invitations['id'], $invitations['schema'], $invitations['scopeField']]);
		self::assertSame(['status' => 'open', 'pupilMayBook' => true], $invitations['filter']);
		self::assertFalse($invitations['listable']);

		self::assertSame(['studentConferenceTimes', 'conference-slot', 'eligibleLearnerRefs', 'learnerRef'], [$times['id'], $times['schema'], $times['scopeField'], $times['scopeClaim']]);
		self::assertSame(['lifecycle' => 'free', 'pupilMayBook' => true], $times['filter']);
		self::assertNotContains('eligibleLearnerRefs', $times['fields']);
		self::assertNotContains('teacherId', $times['fields']);
		self::assertSame(['studentConferenceSignups', 'conference-signup', 'learnerRef'], [$talks['id'], $talks['schema'], $talks['scopeField']]);
		self::assertNotContains('guardianRef', $talks['fields']);

		$action = $booking->bookConferenceSlotAction();
		self::assertSame(['create', 'conference-signup', 'learnerRef', 'learnerRef'], [$action['type'], $action['schema'], $action['scopeField'], $action['scopeClaim']]);
		self::assertSame(['slotId', 'notes'], $action['fields']);
		self::assertSame(['slotId'], $action['requiredFields']);
		self::assertSame('conference-slot', $action['optionsProviders']['slotId']['schema']);
	}//end testSheBooksHerOwnTimeFromTheTimesSheMayBook()

	/**
	 * Her task names the talk and opens the booking page; that page holds
	 * the invitation, the booking form and the times, out of the menu. The
	 * task carries no time itself.
	 *
	 * @return void
	 */
	public function testTheInvitationLinksToTheBookingPage(): void {
		$booking = new StudentSelfBooking();
		$task = $booking->invitationTasksBlock();
		self::assertSame(['tasks', 'studentConferenceInvitations', 'Choose a time'], [$task['type'], $task['collection'], $task['buttonLabel']]);
		self::assertSame(['roundName'], $task['titleFields']);

		$page = $booking->pickATimePage();
		self::assertSame([StudentSelfBooking::PICK_A_TIME_PAGE, false], [$page['id'], $page['menu']]);
		self::assertSame(
			[
				['type' => 'detail', 'collection' => 'studentConferenceInvitations'],
				['type' => 'action', 'action' => 'bookConferenceSlotAsPupil'],
				['type' => 'collection', 'collection' => 'studentConferenceTimes'],
			],
			$page['blocks']
		);
	}//end testTheInvitationLinksToTheBookingPage()
}//end class
