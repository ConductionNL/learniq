<?php

/**
 * The employer's portal: her collections, forms and pages, and the claims
 * her invitation writes.
 *
 * Every key used here survives portaliq's own normaliser on development
 * (ca59103), checked by running this manifest through PortalManifestNormaliser;
 * only the `count` widget is dropped until portaliq ships it.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\EmployerPortalInvitation;
use OCA\Learniq\Portal\EmployerSitePages;
use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Service\Portal\EmployerBookingSteps;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Stand-in for portaliq's provision event.
 */
class FakeEmployerProvisionEvent extends Event {

	/**
	 * What portaliq answers with.
	 *
	 * @var string
	 */
	public string $subjectRef = 'linda-account';

	/**
	 * Constructor.
	 *
	 * @param string $appId         The asking app.
	 * @param string $audience      The portal audience.
	 * @param string $organisation  The portal organisation.
	 * @param string $identityType  The identity type.
	 * @param string $identityRef   The identity reference.
	 * @param string $email         The address to invite.
	 * @param bool   $verifiedEmail Whether it is verified.
	 * @param string $displayName   The person's name.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $audience,
		public readonly string $organisation,
		public readonly string $identityType = '',
		public readonly string $identityRef = '',
		public readonly string $email = '',
		public readonly bool $verifiedEmail = false,
		public readonly string $displayName = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The account.
	 *
	 * @return string
	 */
	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()
}//end class

/**
 * Stand-in for portaliq's claim event.
 */
class FakeEmployerClaimEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $appId      The asking app.
	 * @param string $subjectRef The account.
	 * @param string $claimName  The claim.
	 * @param string $value      Its value.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $subjectRef,
		public readonly string $claimName,
		public readonly string $value,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Always written.
	 *
	 * @return string
	 */
	public function getResult(): string {
		return 'ok';
	}//end getResult()
}//end class

/**
 * The employer audience.
 */
class EmployerSitePagesTest extends TestCase {

	/**
	 * The employer's manifest, as portaliq receives it.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		return (new PortalContributionProvider())->getContribution(['audience' => 'employer']);
	}//end manifest()

	/**
	 * Learniq serves the employer, and her manifest is the employer's pages.
	 *
	 * @return void
	 */
	public function testLearniqServesTheEmployer(): void {
		self::assertContains('employer', (new PortalContributionProvider())->getAudiences());
		$pages = array_column(self::manifest()['pages'], null, 'id');
		self::assertTrue($pages['employerOverview']['home']);
		self::assertSame(['greeting', 'tasks', 'collection', 'cta'], array_column($pages['employerOverview']['blocks'], 'type'));
		self::assertSame('employerBookings', $pages['employerBookings']['record']['collection']);
	}//end testLearniqServesTheEmployer()

	/**
	 * Every read is matched on her company's claim, except the editions she
	 * may book, which are matched on the location claim; nothing is joined.
	 *
	 * @return void
	 */
	public function testEveryReadIsHerCompanysOwn(): void {
		foreach (self::manifest()['collections'] as $collection) {
			self::assertArrayNotHasKey('via', $collection, $collection['id']);
			if ($collection['id'] === 'employerEditions') {
				self::assertSame(['locationId', 'editionLocationRef'], [$collection['scopeField'], $collection['scopeClaim']]);
				continue;
			}

			self::assertSame(['organisationRef', 'organisationRef'], [$collection['scopeField'], $collection['scopeClaim']], $collection['id']);
			self::assertContains('organisationRef', $collection['fields'], $collection['id']);
		}
	}//end testEveryReadIsHerCompanysOwn()

	/**
	 * The birth date is written, never read: no collection projects it, and
	 * no read of a profile shows more than names and department.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-supplies-a-missing-birth-date-and-never-reads-it
	 */
	public function testTheBirthDateIsNeverRead(): void {
		foreach (self::manifest()['collections'] as $collection) {
			self::assertNotContains('birthDate', $collection['fields'], $collection['id']);
			if ($collection['schema'] === 'learner-profile') {
				self::assertSame([], array_diff($collection['fields'], ['organisationRef', 'fullName', 'givenName', 'familyName', 'department', 'lifecycle']));
			}
		}
	}//end testTheBirthDateIsNeverRead()

	/**
	 * Every form posts to learniq with her company stamped from the claim,
	 * and every choice list reads a collection she has over that schema; where
	 * two collections share a schema the narrower one comes first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function testTheFormsStampHerCompanyAndPickFromHerOwnRows(): void {
		$manifest = self::manifest();
		$firstBySchema = [];
		foreach ($manifest['collections'] as $collection) {
			$firstBySchema[$collection['schema']] ??= $collection;
		}

		self::assertSame('employerComingBookings', $firstBySchema['course-booking']['id']);
		self::assertSame('employerOpenTasks', $firstBySchema['enrolment']['id']);
		$actions = array_column($manifest['actions'], null, 'id');
		self::assertSame(['enrolEmployees', 'addBookingParticipant', 'supplyBirthDate'], array_keys($actions));
		foreach ($actions as $action) {
			self::assertSame('endpoint-forward', $action['type']);
			self::assertStringStartsWith('/apps/learniq/api/portal/employer/', $action['endpoint']);
			self::assertSame('organisationRef', $action['subjectField']);
			self::assertSame('organisationRef', $action['scopeClaim']);
			self::assertNotContains('organisationRef', $action['fields'], 'the company is never a form field');
			foreach ($action['optionsProviders'] as $field => $provider) {
				$source = $firstBySchema[$provider['schema']];
				self::assertContains($provider['labelField'], $source['fields'], $action['id'] . '.' . $field);
				if ($provider['valueField'] !== 'id') {
					self::assertContains($provider['valueField'], $source['fields'], $action['id'] . '.' . $field);
				}
			}
		}
	}//end testTheFormsStampHerCompanyAndPickFromHerOwnRows()

	/**
	 * Every block names a collection or action of this manifest, and each
	 * field a block reads is projected by its collection.
	 *
	 * @return void
	 */
	public function testEveryBlockReadsWhatExists(): void {
		$manifest = self::manifest();
		$collections = array_column($manifest['collections'], null, 'id');
		$actions = array_column($manifest['actions'], 'id');
		foreach ($manifest['pages'] as $page) {
			foreach ($page['blocks'] as $block) {
				if (isset($block['collection']) === true) {
					self::assertArrayHasKey($block['collection'], $collections, $page['id']);
					$fields = $collections[$block['collection']]['fields'];
					foreach (['dateField', 'subtitleField', 'statusField', 'statusNoteField', 'dueField', 'recordField'] as $key) {
						if (isset($block[$key]) === true) {
							self::assertContains($block[$key], $fields, $page['id'] . ' ' . $key);
						}
					}

					foreach (array_merge(($block['titleFields'] ?? []), ($block['subtitleFields'] ?? [])) as $field) {
						self::assertContains($field, $fields, $page['id']);
					}
				}

				if (isset($block['action']) === true) {
					self::assertContains($block['action'], $actions, $page['id']);
				}
			}
		}

		self::assertSame(EmployerSitePages::STEPS_PROVIDER, $collections['employerBookings']['steps']['provider']);
		self::assertSame('cases', $collections['employerBookings']['kind']);
		self::assertTrue(method_exists(PortalContributionProvider::class, EmployerSitePages::STEPS_PROVIDER));
	}//end testEveryBlockReadsWhatExists()

	/**
	 * The steps provider answers through the service, and nothing without it.
	 *
	 * @return void
	 */
	public function testTheStepsProviderAsksTheService(): void {
		self::assertSame([], (new PortalContributionProvider())->employerBookingSteps(id: 'b-1'));
		$steps = $this->createMock(EmployerBookingSteps::class);
		$steps->expects(self::once())->method('forBooking')->with('b-1')->willReturn([['label' => 'Booked', 'state' => 'done']]);
		self::assertSame([['label' => 'Booked', 'state' => 'done']], (new PortalContributionProvider(bookingSteps: $steps))->employerBookingSteps(id: 'b-1'));
	}//end testTheStepsProviderAsksTheService()

	/**
	 * The invitation provisions an `employer` account with the company's
	 * eHerkenning reference, and writes exactly the claims the collections
	 * are scoped by, plus the company's name for the session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function testTheInvitationWritesTheClaimsTheCollectionsRead(): void {
		$store = new RegisterFaithfulStore();
		$store->rows = [
			'client-organisation' => [
				[
					'id' => 'co-jansen',
					'name' => 'Jansen Installatietechniek BV',
					'eherkenningRef' => 'eherkenning-jansen',
					'contactName' => 'Linda Jansen',
					'contactEmail' => 'linda@jansen.example',
					'locationId' => 'loc-1',
					'lifecycle' => 'active',
				],
				['id' => 'co-gone', 'name' => 'Weg BV', 'contactEmail' => 'x@weg.example', 'lifecycle' => 'inactive'],
			],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(fn (array $config = []): array => $store->findAll($config, false, false));
		$dispatched = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event) use (&$dispatched): void {
			$dispatched[] = $event;
		});
		$invitation = new EmployerPortalInvitation(
			objectService: $objectService,
			dispatcher: $dispatcher,
			logger: new NullLogger(),
			provisionEventClass: FakeEmployerProvisionEvent::class,
			claimEventClass: FakeEmployerClaimEvent::class
		);

		self::assertSame(['status' => 'invited', 'subjectRef' => 'linda-account'], $invitation->invite(organisationRef: 'co-jansen', organisation: 'default-organisation'));
		self::assertSame(['employer', 'eherkenning', 'eherkenning-jansen', 'linda@jansen.example', 'Linda Jansen'], [$dispatched[0]->audience, $dispatched[0]->identityType, $dispatched[0]->identityRef, $dispatched[0]->email, $dispatched[0]->displayName]);
		$claims = [];
		foreach (array_slice($dispatched, 1) as $claim) {
			$claims[$claim->claimName] = $claim->value;
		}

		self::assertSame(['organisationRef' => 'co-jansen', 'organisationName' => 'Jansen Installatietechniek BV', 'editionLocationRef' => 'loc-1'], $claims);
		$read = array_unique(array_column(self::manifest()['collections'], 'scopeClaim'));
		self::assertSame([], array_diff($read, array_keys($claims)), 'every claim a collection reads is written');

		self::assertSame(['status' => 'refused', 'reason' => 'company-unknown'], $invitation->invite(organisationRef: 'co-gone', organisation: 'default-organisation'));
		self::assertSame(['status' => 'refused', 'reason' => 'company-unknown'], $invitation->invite(organisationRef: 'co-none', organisation: 'default-organisation'));
		self::assertSame(['status' => 'refused', 'reason' => 'email-invalid'], $invitation->invite(organisationRef: 'co-jansen', organisation: 'o', email: 'geen-adres'));
	}//end testTheInvitationWritesTheClaimsTheCollectionsRead()
}//end class
