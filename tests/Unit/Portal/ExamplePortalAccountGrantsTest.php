<?php

/**
 * Tests for the example portal accounts (example-portal-install-steps).
 *
 * @category Test
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Command\ExampleSetLoadCommand;
use OCA\Learniq\Portal\ExamplePortalAccountGrants;
use OCA\Learniq\Portal\ExamplePortalContent;
use OCA\Learniq\Portal\ExamplePortalDeclarations;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stand-in for portaliq's provision event after #1381: the same constructor
 * (two trailing arguments `nextcloudUid` and `portal`) and answer API.
 */
class FakeNextcloudProvisionEvent extends Event {
	public string $subjectRef = '';

	public string $status = '';

	public string $refusal = '';

	/**
	 * @param string $appId
	 * @param string $audience
	 * @param string $organisation
	 * @param string $identityType
	 * @param string $identityRef
	 * @param string $email
	 * @param bool   $verifiedEmail
	 * @param string $displayName
	 * @param string $nextcloudUid
	 * @param string $portal
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $audience,
		public readonly string $organisation,
		public readonly string $identityType='',
		public readonly string $identityRef='',
		public readonly string $email='',
		public readonly bool $verifiedEmail=false,
		public readonly string $displayName='',
		public readonly string $nextcloudUid='',
		public readonly string $portal='',
	) {
		parent::__construct();
	}//end __construct()

	public function getNextcloudUid(): string {
		return $this->nextcloudUid;
	}//end getNextcloudUid()

	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()

	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	public function getRefusal(): string {
		return $this->refusal;
	}//end getRefusal()
}//end class

/**
 * Stand-in for portaliq's provision event BEFORE #1381: no Nextcloud user id.
 */
class FakeOlderProvisionEvent extends Event {
}//end class

/**
 * Stand-in for portaliq's claim event.
 */
class FakeGrantClaimEvent extends Event {
	public string $result = 'ok';

	/**
	 * @param string $appId
	 * @param string $subjectRef
	 * @param string $claimName
	 * @param string $value
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $subjectRef,
		public readonly string $claimName,
		public readonly string $value,
	) {
		parent::__construct();
	}//end __construct()

	public function getResult(): string {
		return $this->result;
	}//end getResult()
}//end class

/**
 * The training load gives Tom Verbeek his participant account, once.
 *
 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
 */
class ExamplePortalAccountGrantsTest extends TestCase {

	private const TOM = 'training-deelnemer-151';

	private const TOM_REF = 'ee06000c-0000-4000-8000-000000000158';

	/**
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * A grants service over the real training declaration.
	 *
	 * @param array<int, array<string, mixed>> $accounts The stored portal accounts.
	 * @param string                           $organisation The portal's organisation.
	 * @param string                           $refusal  What portaliq answers to a provision.
	 * @param string                           $provisionClass The provision event class.
	 *
	 * @return ExamplePortalAccountGrants
	 */
	private function grants(array $accounts=[], string $organisation='default-organisation', string $refusal='', string $provisionClass=FakeNextcloudProvisionEvent::class): ExamplePortalAccountGrants {
		$this->dispatched = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($refusal): void {
				if ($event instanceof FakeNextcloudProvisionEvent) {
					if ($refusal !== '') {
						$event->refusal = $refusal;
					} else {
						$event->subjectRef = $event->nextcloudUid;
						$event->status     = 'active';
					}
				}

				$this->dispatched[] = $event;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$rows = [
			'portal'        => [['slug' => 'warmtepompacademie', 'organisation' => $organisation]],
			'portalAccount' => $accounts,
		];
		$content = $this->createMock(ExamplePortalContent::class);
		$content->method('findOne')->willReturnCallback(
			static function (string $schema, callable $match) use ($rows): ?array {
				foreach (($rows[$schema] ?? []) as $row) {
					if ($match($row) === true) {
						return $row;
					}
				}

				return null;
			}
		);

		return new ExamplePortalAccountGrants(
			dispatcher: $dispatcher,
			appManager: $apps,
			content: $content,
			declarations: new ExamplePortalDeclarations(),
			logger: $this->createMock(LoggerInterface::class),
			provisionEventClass: $provisionClass,
			claimEventClass: FakeGrantClaimEvent::class,
		);
	}//end grants()

	/**
	 * A fresh load asks for Tom's active participant account on his portal,
	 * then writes his learnerRef claim.
	 *
	 * @return void
	 */
	public function testAFreshLoadGivesTomHisParticipantAccount(): void {
		$result = $this->grants()->grant(profileId: 'training');

		$this->assertSame(['done', 1, 0, 0, 0], [$result['status'], $result['granted'], $result['kept'], $result['waiting'], $result['failed']]);
		$this->assertCount(2, $this->dispatched);
		$provision = $this->dispatched[0];
		$this->assertInstanceOf(FakeNextcloudProvisionEvent::class, $provision);
		$this->assertSame(
			['learniq', 'participant', 'default-organisation', self::TOM, 'warmtepompacademie', 'Tom Verbeek'],
			[$provision->appId, $provision->audience, $provision->organisation, $provision->nextcloudUid, $provision->portal, $provision->displayName]
		);
		$claim = $this->dispatched[1];
		$this->assertInstanceOf(FakeGrantClaimEvent::class, $claim);
		$this->assertSame([self::TOM, 'learnerRef', self::TOM_REF], [$claim->subjectRef, $claim->claimName, $claim->value]);
	}//end testAFreshLoadGivesTomHisParticipantAccount()

	/**
	 * A second load finds the account complete and asks portaliq nothing.
	 *
	 * @return void
	 */
	public function testASecondLoadWritesNothing(): void {
		$stored = ['subjectRef' => self::TOM, 'audience' => 'participant', 'status' => 'active', 'claims' => ['learniq' => ['learnerRef' => self::TOM_REF]]];
		$result = $this->grants(accounts: [$stored])->grant(profileId: 'training');

		$this->assertSame([0, 1], [$result['granted'], $result['kept']]);
		$this->assertSame([], $this->dispatched);
	}//end testASecondLoadWritesNothing()

	/**
	 * An active account without the claim gets only the claim.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutTheClaimGetsOnlyTheClaim(): void {
		$stored = ['subjectRef' => self::TOM, 'audience' => 'participant', 'status' => 'active', 'claims' => []];
		$result = $this->grants(accounts: [$stored])->grant(profileId: 'training');

		$this->assertSame(1, $result['granted']);
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(FakeGrantClaimEvent::class, $this->dispatched[0]);
	}//end testAnAccountWithoutTheClaimGetsOnlyTheClaim()

	/**
	 * Without an organisation on the portal nothing is asked, and the load says so.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAnOrganisationWaits(): void {
		$result = $this->grants(organisation: '')->grant(profileId: 'training');

		$this->assertSame([1, 0], [$result['waiting'], $result['failed']]);
		$this->assertSame([], $this->dispatched);
		$this->assertStringContainsString('wait for the portal\'s organisation', ExampleSetLoadCommand::describeGrants(grants: $result));
	}//end testAPortalWithoutAnOrganisationWaits()

	/**
	 * A refusal is counted with its reason, and no claim is written.
	 *
	 * @return void
	 */
	public function testARefusalIsReportedAndNoClaimIsWritten(): void {
		$result = $this->grants(refusal: 'conflict')->grant(profileId: 'training');

		$this->assertSame(1, $result['failed']);
		$this->assertSame([self::TOM . ': conflict'], $result['reasons']);
		$this->assertCount(1, $this->dispatched);
	}//end testARefusalIsReportedAndNoClaimIsWritten()

	/**
	 * A portaliq from before the Nextcloud provisioning is asked nothing.
	 *
	 * @return void
	 */
	public function testAnOlderPortaliqIsAskedNothing(): void {
		$result = $this->grants(provisionClass: FakeOlderProvisionEvent::class)->grant(profileId: 'training');

		$this->assertSame('portaliq-too-old', $result['status']);
		$this->assertSame([], $this->dispatched);
	}//end testAnOlderPortaliqIsAskedNothing()

	/**
	 * The classes this app names are portaliq's own, and every declared
	 * portal account is a learner of its set with that learner's own uuid.
	 *
	 * @return void
	 */
	public function testTheDeclaredLearnersAreRealPeopleOfTheSet(): void {
		$this->assertSame('OCA\\Portaliq\\Event\\PortalAccountProvisionRequestedEvent', ExamplePortalAccountGrants::PROVISION_EVENT);
		$this->assertSame('OCA\\Portaliq\\Event\\PortalAccountClaimRequestedEvent', ExamplePortalAccountGrants::CLAIM_EVENT);

		$declarations = new ExamplePortalDeclarations();
		$granted      = [];
		foreach ($declarations->declaredSets() as $set) {
			$objects  = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/' . $set . '.json'), true)['x-openregister']['seedData']['objects'];
			$learners = array_column(($objects['learner-profile'] ?? []), 'uuid', 'ncUserId');
			foreach ($declarations->forSet(setId: $set)['accounts'] as $account) {
				if (isset($account['portal']) === false) {
					continue;
				}

				$this->assertContains($account['portal']['audience'], ['student', 'participant'], $account['userId']);
				$this->assertSame($learners[$account['userId']] ?? null, $account['portal']['claims']['learnerRef'], $account['userId']);
				$granted[] = $account['userId'];
			}
		}

		$this->assertSame(['mbo-student-251', 'mbo-student-252', 'training-deelnemer-151', 'vo-leerling-121'], $granted);
	}//end testTheDeclaredLearnersAreRealPeopleOfTheSet()
}//end class
