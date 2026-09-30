<?php

/**
 * GuardianPortalInvitation test.
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
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\GuardianPortalInvitation;
use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Service\LearnerRefResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stand-in for portaliq's provision event, same constructor and answer API.
 */
class FakeProvisionEvent extends Event {
	public string $subjectRef = '';

	/**
	 * @param string $appId
	 * @param string $audience
	 * @param string $organisation
	 * @param string $identityType
	 * @param string $identityRef
	 * @param string $email
	 * @param bool $verifiedEmail
	 * @param string $displayName
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
	) {
		parent::__construct();
	}//end __construct()

	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()
}//end class

/**
 * Stand-in for portaliq's claim event.
 */
class FakeClaimEvent extends Event {
	public string $result = '';

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
 * The invitation provisions the account and writes the claim the parent
 * contribution scopes by.
 */
class GuardianPortalInvitationTest extends TestCase {

	private const GUARDIAN = 'ee010008-0000-4000-8000-000000000009';

	/** @var array<int, Event> */
	private array $dispatched = [];

	/**
	 * A guardian is provisioned with a verified email and gets the
	 * learniq.guardianRef claim; the claim name is the one every parent
	 * collection declares.
	 *
	 * @return void
	 */
	public function testAGuardianIsProvisionedAndLinked(): void {
		$result = $this->invitation(profile: $this->guardianProfile())
			->invite(guardianRef: self::GUARDIAN, email: 'fatima@example.org', organisation: 'de-wilgenboom');

		$this->assertSame(['status' => 'invited', 'subjectRef' => 'subject-1'], $result);

		$provision = $this->dispatched[0];
		$this->assertInstanceOf(FakeProvisionEvent::class, $provision);
		$this->assertSame('learniq', $provision->appId);
		$this->assertSame('parent', $provision->audience);
		$this->assertSame('de-wilgenboom', $provision->organisation);
		$this->assertSame('fatima@example.org', $provision->email);
		$this->assertTrue($provision->verifiedEmail);
		$this->assertSame('Fatima Hulstkamp', $provision->displayName);

		$claim = $this->dispatched[1];
		$this->assertInstanceOf(FakeClaimEvent::class, $claim);
		$this->assertSame(['learniq', 'subject-1', 'guardianRef', self::GUARDIAN], [$claim->appId, $claim->subjectRef, $claim->claimName, $claim->value]);

		$parent = (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
		foreach ($parent['collections'] as $collection) {
			$this->assertSame(GuardianPortalInvitation::CLAIM_NAME, $collection['scopeClaim']);
		}
	}//end testAGuardianIsProvisionedAndLinked()

	/**
	 * A profile without the parent role, an unknown uuid, a bad address or
	 * a missing organisation is refused before anything is dispatched.
	 *
	 * @return void
	 */
	public function testBadInputIsRefusedWithoutDispatching(): void {
		$pupil = array_merge($this->guardianProfile(), ['roles' => ['learner']]);

		$this->assertSame('guardian-unknown', $this->invitation(profile: $pupil)->invite(self::GUARDIAN, 'a@example.org', 'org')['reason']);
		$this->assertSame('guardian-unknown', $this->invitation(profile: null)->invite(self::GUARDIAN, 'a@example.org', 'org')['reason']);
		$this->assertSame('email-invalid', $this->invitation(profile: $this->guardianProfile())->invite(self::GUARDIAN, 'not-an-address', 'org')['reason']);
		$this->assertSame('organisation-missing', $this->invitation(profile: $this->guardianProfile())->invite(self::GUARDIAN, 'a@example.org', ' ')['reason']);
		$this->assertSame([], $this->dispatched);
	}//end testBadInputIsRefusedWithoutDispatching()

	/**
	 * Without portaliq nothing is dispatched and the answer says so.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqTheInvitationSaysThePortalIsUnavailable(): void {
		$invitation = new GuardianPortalInvitation(
			$this->profiles(profile: $this->guardianProfile()),
			$this->dispatcher(),
			$this->createMock(LoggerInterface::class),
			'OCA\\Portaliq\\Event\\NoSuchEvent',
			'OCA\\Portaliq\\Event\\NoSuchEventEither'
		);

		$this->assertSame(['status' => 'refused', 'reason' => 'portal-unavailable'], $invitation->invite(self::GUARDIAN, 'a@example.org', 'org'));
		$this->assertSame([], $this->dispatched);
	}//end testWithoutPortaliqTheInvitationSaysThePortalIsUnavailable()

	/**
	 * A claim portaliq does not confirm is reported, not swallowed.
	 *
	 * @return void
	 */
	public function testAnUnconfirmedClaimIsRefused(): void {
		$result = $this->invitation(profile: $this->guardianProfile(), claimResult: 'refused')
			->invite(self::GUARDIAN, 'a@example.org', 'org');

		$this->assertSame(['status' => 'refused', 'reason' => 'claim-refused'], $result);
	}//end testAnUnconfirmedClaimIsRefused()

	/**
	 * The guardian's profile from the po example set.
	 *
	 * @return array<string, mixed>
	 */
	private function guardianProfile(): array {
		return [
			'id' => self::GUARDIAN,
			'givenName' => 'Fatima',
			'familyName' => 'Hulstkamp',
			'roles' => ['parent'],
		];
	}//end guardianProfile()

	/**
	 * The invitation, wired to fakes.
	 *
	 * @param array<string, mixed>|null $profile What byRef() answers.
	 * @param string $claimResult What portaliq answers the claim with.
	 *
	 * @return GuardianPortalInvitation
	 */
	private function invitation(?array $profile, string $claimResult='ok'): GuardianPortalInvitation {
		return new GuardianPortalInvitation(
			$this->profiles(profile: $profile),
			$this->dispatcher(claimResult: $claimResult),
			$this->createMock(LoggerInterface::class),
			FakeProvisionEvent::class,
			FakeClaimEvent::class
		);
	}//end invitation()

	/**
	 * @param array<string, mixed>|null $profile What byRef() answers.
	 *
	 * @return LearnerRefResolver
	 */
	private function profiles(?array $profile): LearnerRefResolver {
		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('byRef')->willReturn($profile);
		return $profiles;
	}//end profiles()

	/**
	 * A dispatcher that answers the events the way portaliq's listeners do.
	 *
	 * @param string $claimResult What the claim listener answers.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(string $claimResult='ok'): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($claimResult): void {
				$this->dispatched[] = $event;
				if ($event instanceof FakeProvisionEvent) {
					$event->subjectRef = 'subject-1';
				}

				if ($event instanceof FakeClaimEvent) {
					$event->result = $claimResult;
				}
			}
		);
		return $dispatcher;
	}//end dispatcher()
}//end class
