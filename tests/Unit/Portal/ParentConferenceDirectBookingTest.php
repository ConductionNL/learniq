<?php

/**
 * Parent portal direct conference booking test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ParentPortalCollections;
use PHPUnit\Framework\TestCase;

/**
 * The guardian sees free times for their own children, books one through
 * a child picker portaliq checks, and cancels a time they booked.
 */
class ParentConferenceDirectBookingTest extends TestCase {

	/**
	 * The child join every parent read uses.
	 */
	private const CHILD_JOIN = [
		'register' => 'learniq',
		'schema' => 'learner-profile',
		'scopeField' => 'guardianRefs',
		'targetField' => 'id',
		'match' => 'scopeField',
	];

	/**
	 * Free times are scoped by the pupils who may book them, only `free`
	 * ones are listed, and they come before the conference times, because
	 * portaliq fills the time picker from the first collection over
	 * conference-slot.
	 *
	 * @return void
	 */
	public function testFreeTimesAreTheChildrensOwnAndFeedThePicker(): void {
		$collections = (new ParentPortalCollections())->conferenceCollections(childJoin: self::CHILD_JOIN);
		$ids = array_column($collections, 'id');
		$byId = array_column($collections, null, 'id');

		$free = $byId['parentConferenceFreeSlots'];
		$this->assertSame('eligibleLearnerRefs', $free['scopeField']);
		$this->assertSame(self::CHILD_JOIN, $free['via']);
		$this->assertSame(['lifecycle' => 'free'], $free['filter']);
		$this->assertNotContains('learnerRef', $free['fields'], 'a free time names no child');
		$this->assertLessThan(array_search('parentConferenceSlots', $ids, true), array_search('parentConferenceFreeSlots', $ids, true));

		// Field names the portal calendar reads (FIELDS.md): add, never rename.
		foreach (['startsAt', 'endsAt', 'teacherId', 'teacherName', 'location', 'lifecycle', 'conferenceRoundId', 'slotLabel', 'learnerRef'] as $field) {
			$this->assertContains($field, $byId['parentConferenceSlots']['fields']);
		}

		$this->assertSame(['cancelConferenceTime'], $byId['parentConferenceSlots']['rowActions']);
		$this->assertContains('declineNote', $byId['parentConferenceSignups']['fields']);
	}//end testFreeTimesAreTheChildrensOwnAndFeedThePicker()

	/**
	 * Booking names the child (checked against the guardian's own children)
	 * and the time; cancelling only sets `cancelled`, on a time the guardian
	 * booked herself.
	 *
	 * @return void
	 */
	public function testBookingAndCancellingAreTheGuardiansOwn(): void {
		$extras = new ParentPortalCollections();
		$actions = array_column($extras->conferenceActions(), null, 'id');

		$book = $actions['bookConferenceSlot'];
		$this->assertSame('create', $book['type']);
		$this->assertSame('conference-signup', $book['schema']);
		$this->assertSame('guardianRef', $book['scopeField']);
		$this->assertSame('substantial', $book['minTrust']);
		$this->assertSame(['learnerRef', 'slotId', 'notes'], $book['fields']);
		$this->assertSame($extras->childCrossRef(), $book['crossRefs']['learnerRef']);
		$this->assertSame('slotLabel', $book['optionsProviders']['slotId']['labelField']);
		$this->assertSame('conference-slot', $book['optionsProviders']['slotId']['schema']);

		$cancel = $actions['cancelConferenceTime'];
		$this->assertSame('update', $cancel['type']);
		$this->assertSame('conference-slot', $cancel['schema']);
		$this->assertSame('guardianRef', $cancel['scopeField']);
		$this->assertSame(['lifecycle'], $cancel['fields']);
		$this->assertSame(['lifecycle' => 'cancelled'], $cancel['set']);

		$this->assertArrayHasKey('createConferenceSignup', $actions, 'the preference flow stays');
	}//end testBookingAndCancellingAreTheGuardiansOwn()

	/**
	 * Both conference forms show: portaliq would put the first signup form on
	 * every signup page, so the free times page carries "Book a time" and the
	 * bookings page the request form. Every other page is portaliq's default.
	 *
	 * @return void
	 */
	public function testEachConferenceFormHasItsOwnPage(): void {
		$extras = new ParentPortalCollections();
		$collections = array_merge(
			[['id' => 'parentExcuseRequests', 'schema' => 'excuse-request', 'listable' => true, 'label' => 'Absences']],
			$extras->conferenceCollections(childJoin: self::CHILD_JOIN),
			[['id' => 'hidden', 'schema' => 'enrolment', 'listable' => false]]
		);
		$actions = array_merge([['id' => 'createExcuseRequest', 'type' => 'create', 'schema' => 'excuse-request']], $extras->conferenceActions());

		$pages = array_column($extras->pages(collections: $collections, actions: $actions), null, 'id');

		$this->assertSame(['type' => 'action', 'action' => 'bookConferenceSlot'], $pages['parentConferenceFreeSlots']['blocks'][0]);
		$this->assertSame(['type' => 'action', 'action' => 'createConferenceSignup'], $pages['parentConferenceSignups']['blocks'][0]);
		$this->assertSame(['type' => 'action', 'action' => 'createExcuseRequest'], $pages['parentExcuseRequests']['blocks'][0]);
		$this->assertSame(['type' => 'collection', 'collection' => 'parentConferenceSlots'], $pages['parentConferenceSlots']['blocks'][0]);
		$this->assertArrayNotHasKey('hidden', $pages);
	}//end testEachConferenceFormHasItsOwnPage()
}//end class
