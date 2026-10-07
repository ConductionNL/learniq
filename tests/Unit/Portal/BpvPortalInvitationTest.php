<?php

/**
 * Tests for BpvPortalInvitation.
 *
 * The claim is the part that matters: without it the trainer's and the
 * assessor's portal scopes resolve nothing, so each test checks the claim name
 * against the one the contribution declares.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\AssessorSitePages;
use OCA\Learniq\Portal\BpvPortalInvitation;
use OCA\Learniq\Portal\TrainerSitePages;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Stand-in for portaliq's provision event, with the constructor and the answer
 * API the real one has. Declared here rather than borrowed from another test
 * file, so this file can run on its own.
 */
class FakeBpvProvisionEvent extends Event {

	/**
	 * What portaliq answers with.
	 *
	 * @var string
	 */
	public string $subjectRef = '';

	/**
	 * Constructor.
	 *
	 * @param string $appId         The asking app.
	 * @param string $audience      The portal audience.
	 * @param string $organisation  The portal organisation.
	 * @param string $identityType  The identity type, unused here.
	 * @param string $identityRef   The identity reference, unused here.
	 * @param string $email         The address to invite.
	 * @param bool   $verifiedEmail Whether the school verified it.
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
	 * The account portaliq provisioned.
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
class FakeBpvClaimEvent extends Event {

	/**
	 * What portaliq answers.
	 *
	 * @var string
	 */
	public string $result = '';

	/**
	 * Constructor.
	 *
	 * @param string $appId      The asking app.
	 * @param string $subjectRef The account.
	 * @param string $claimName  The claim to write.
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
	 * Whether portaliq wrote the claim.
	 *
	 * @return string
	 */
	public function getResult(): string {
		return $this->result;
	}//end getResult()
}//end class

/**
 * A school invites a trainer or an assessor it already created.
 */
class BpvPortalInvitationTest extends TestCase {

	private const TRAINER = 'ee030010-0000-4000-8000-000000000001';

	private const ASSESSOR = 'ee030031-0000-4000-8000-000000000001';

	/**
	 * Every event the invitation dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the invitation over the fake store and the stand-in events.
	 *
	 * @param string $subjectRef What portaliq answers with, '' to refuse.
	 * @param string $claimResult What the claim event answers.
	 * @param bool   $activeAssessor Whether the assessor row is active.
	 * @param bool   $failReads Whether reading throws.
	 *
	 * @return BpvPortalInvitation
	 */
	private function invitation(string $subjectRef = 'subject-1', string $claimResult = 'ok', bool $activeAssessor = true, bool $failReads = false): BpvPortalInvitation {
		$this->dispatched = [];
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'praktijkopleider' => [
				[
					'id' => self::TRAINER,
					'givenName' => 'Karin',
					'familyName' => 'Smit',
					'email' => 'karin.smit@vandam.example',
					'trainingCompanyName' => 'Installatiebedrijf Van Dam',
					'active' => true,
				],
			],
			'external-assessor' => [
				[
					'id' => self::ASSESSOR,
					'givenName' => 'Ruud',
					'familyName' => 'Jansen',
					'email' => 'ruud.jansen@examinering.example',
					'organisationName' => 'Examinering Zuiddrecht',
					'active' => $activeAssessor,
				],
			],
		];
		if ($failReads === true) {
			$this->store->failReads = 'database gone';
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($subjectRef, $claimResult): void {
				$this->dispatched[] = $event;
				if ($event instanceof FakeBpvProvisionEvent) {
					$event->subjectRef = $subjectRef;
					return;
				}

				if ($event instanceof FakeBpvClaimEvent) {
					$event->result = $claimResult;
				}
			}
		);

		return new BpvPortalInvitation(
			objectService: $objectService,
			dispatcher: $dispatcher,
			logger: new NullLogger(),
			provisionEventClass: FakeBpvProvisionEvent::class,
			claimEventClass: FakeBpvClaimEvent::class,
		);
	}//end invitation()

	/**
	 * A trainer is provisioned as `praktijkopleider` and gets the
	 * `practicalTrainerId` claim, with the address on her own record.
	 *
	 * @return void
	 */
	public function testATrainerGetsHerOwnClaim(): void {
		$result = $this->invitation()->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');

		self::assertSame(['status' => 'invited', 'subjectRef' => 'subject-1'], $result);

		$provision = $this->dispatched[0];
		self::assertInstanceOf(FakeBpvProvisionEvent::class, $provision);
		self::assertSame('learniq', $provision->appId);
		self::assertSame('praktijkopleider', $provision->audience);
		self::assertSame('esdoorn', $provision->organisation);
		self::assertSame('karin.smit@vandam.example', $provision->email);
		self::assertTrue($provision->verifiedEmail);
		self::assertSame('Karin Smit', $provision->displayName);

		$claim = $this->dispatched[1];
		self::assertInstanceOf(FakeBpvClaimEvent::class, $claim);
		self::assertSame('subject-1', $claim->subjectRef);
		self::assertSame('practicalTrainerId', $claim->claimName);
		self::assertSame(self::TRAINER, $claim->value);
	}//end testATrainerGetsHerOwnClaim()

	/**
	 * An assessor is provisioned as `external-assessor` and gets the
	 * `externalAssessorId` claim; a given address replaces the stored one.
	 *
	 * @return void
	 */
	public function testAnAssessorGetsHisOwnClaim(): void {
		$result = $this->invitation()->invite(
			role: 'assessor',
			personRef: self::ASSESSOR,
			organisation: 'esdoorn',
			email: 'ruud@zijn-eigen-adres.example'
		);

		self::assertSame('invited', $result['status']);
		self::assertSame('external-assessor', $this->dispatched[0]->audience);
		self::assertSame('ruud@zijn-eigen-adres.example', $this->dispatched[0]->email);
		self::assertSame('externalAssessorId', $this->dispatched[1]->claimName);
		self::assertSame(self::ASSESSOR, $this->dispatched[1]->value);
	}//end testAnAssessorGetsHisOwnClaim()

	/**
	 * The claim each role writes is the `scopeClaim` its own collections
	 * declare, so an invitation can never produce an account the portal then
	 * resolves nothing for.
	 *
	 * @return void
	 */
	public function testTheClaimsMatchWhatTheContributionsScopeBy(): void {
		$this->invitation()->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');
		$trainerClaim = $this->dispatched[1]->claimName;
		$this->invitation()->invite(role: 'assessor', personRef: self::ASSESSOR, organisation: 'esdoorn');
		$assessorClaim = $this->dispatched[1]->claimName;

		foreach ((new TrainerSitePages())->contribution()['collections'] as $collection) {
			self::assertSame($trainerClaim, $collection['scopeClaim'], $collection['id']);
		}

		foreach ((new AssessorSitePages())->contribution()['collections'] as $collection) {
			self::assertSame($assessorClaim, $collection['scopeClaim'], $collection['id']);
		}
	}//end testTheClaimsMatchWhatTheContributionsScopeBy()

	/**
	 * A uuid the school never created, a switched-off person, an unknown role
	 * and a missing organisation are each refused without an event.
	 *
	 * @return void
	 */
	public function testAnInvitationNeverMintsAPerson(): void {
		$unknown = $this->invitation()->invite(role: 'trainer', personRef: 'ghost', organisation: 'esdoorn');
		self::assertSame(['status' => 'refused', 'reason' => 'person-unknown'], $unknown);
		self::assertSame([], $this->dispatched);

		$empty = $this->invitation()->invite(role: 'trainer', personRef: '', organisation: 'esdoorn');
		self::assertSame('person-unknown', $empty['reason']);

		$switchedOff = $this->invitation(activeAssessor: false)->invite(role: 'assessor', personRef: self::ASSESSOR, organisation: 'esdoorn');
		self::assertSame('person-unknown', $switchedOff['reason']);
		self::assertSame([], $this->dispatched);

		$role = $this->invitation()->invite(role: 'headmaster', personRef: self::TRAINER, organisation: 'esdoorn');
		self::assertSame('role-unknown', $role['reason']);

		$organisation = $this->invitation()->invite(role: 'trainer', personRef: self::TRAINER, organisation: '  ');
		self::assertSame('organisation-missing', $organisation['reason']);
		self::assertSame([], $this->dispatched);
	}//end testAnInvitationNeverMintsAPerson()

	/**
	 * An address that is not an address is refused, and a refusal from
	 * portaliq is reported rather than swallowed.
	 *
	 * @return void
	 */
	public function testRefusalsAreReported(): void {
		$bad = $this->invitation()->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn', email: 'not-an-address');
		self::assertSame('email-invalid', $bad['reason']);
		self::assertSame([], $this->dispatched);

		$noAccount = $this->invitation(subjectRef: '')->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');
		self::assertSame('provision-refused', $noAccount['reason']);

		$noClaim = $this->invitation(claimResult: 'denied')->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');
		self::assertSame('claim-refused', $noClaim['reason']);

		$unreadable = $this->invitation(failReads: true)->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');
		self::assertSame('portal-unavailable', $unreadable['reason']);
		self::assertSame([], $this->dispatched);
	}//end testRefusalsAreReported()

	/**
	 * Without portaliq installed the events do not exist, and the invitation
	 * says so instead of throwing.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqItSaysSo(): void {
		$objectService = $this->createMock(ObjectService::class);
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = ['praktijkopleider' => [['id' => self::TRAINER, 'email' => 'karin.smit@vandam.example', 'active' => true]]];
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');

		$result = (new BpvPortalInvitation(
			objectService: $objectService,
			dispatcher: $dispatcher,
			logger: new NullLogger(),
			provisionEventClass: 'OCA\\Portaliq\\Event\\NoSuchEvent',
			claimEventClass: 'OCA\\Portaliq\\Event\\NoSuchClaimEvent',
		))->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');

		self::assertSame('portal-unavailable', $result['reason']);
	}//end testWithoutPortaliqItSaysSo()

	/**
	 * Both roles are offered, and nothing else.
	 *
	 * @return void
	 */
	public function testTheRolesAreTrainerAndAssessor(): void {
		self::assertSame(['trainer', 'assessor'], BpvPortalInvitation::roles());
	}//end testTheRolesAreTrainerAndAssessor()

	/**
	 * A dispatcher that throws is reported as unavailable, never as success.
	 *
	 * @return void
	 */
	public function testAThrowingDispatcherIsReported(): void {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = ['praktijkopleider' => [['id' => self::TRAINER, 'email' => 'karin.smit@vandam.example', 'active' => true]]];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('portaliq is down'));

		$result = (new BpvPortalInvitation(
			objectService: $objectService,
			dispatcher: $dispatcher,
			logger: new NullLogger(),
			provisionEventClass: FakeBpvProvisionEvent::class,
			claimEventClass: FakeBpvClaimEvent::class,
		))->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');

		self::assertSame('portal-unavailable', $result['reason']);
	}//end testAThrowingDispatcherIsReported()

	/**
	 * A store that ignores `ids` and answers with a neighbour's row must not
	 * get that neighbour invited: the uuid asked for is the one checked.
	 *
	 * @return void
	 */
	public function testARowThatIsNotTheOneAskedForIsSkipped(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				[
					'id' => 'ee030010-0000-4000-8000-000000000099',
					'givenName' => 'Iemand',
					'familyName' => 'Anders',
					'email' => 'iemand.anders@vandam.example',
					'active' => true,
				],
			]
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');

		$result = (new BpvPortalInvitation(
			objectService: $objectService,
			dispatcher: $dispatcher,
			logger: new NullLogger(),
			provisionEventClass: FakeBpvProvisionEvent::class,
			claimEventClass: FakeBpvClaimEvent::class,
		))->invite(role: 'trainer', personRef: self::TRAINER, organisation: 'esdoorn');

		self::assertSame('person-unknown', $result['reason']);
	}//end testARowThatIsNotTheOneAskedForIsSkipped()
}//end class
