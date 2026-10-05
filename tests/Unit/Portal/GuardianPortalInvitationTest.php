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
 * Stand-in for portaliq's invitation event, same constructor and answer API
 * (portaliq `PortalAccountInvitationRequestedEvent`: `appId`, `subjectRef`,
 * `getResult()` answering `sent`, `not_sent` or `refused`).
 */
class FakeInvitationEvent extends Event {
	public string $result = '';

	public string $code = '';

	public string $expiresAt = '';

	/**
	 * @param string $appId
	 * @param string $subjectRef
	 * @param string $channel
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $subjectRef,
		public readonly string $channel='mail',
	) {
		parent::__construct();
	}//end __construct()

	public function getResult(): string {
		return $this->result;
	}//end getResult()

	public function getCode(): string {
		return $this->code;
	}//end getCode()

	public function getExpiresAt(): string {
		return $this->expiresAt;
	}//end getExpiresAt()
}//end class

/**
 * Stand-in for portaliq's invitation event from BEFORE it had a channel
 * (portaliq `invitation-secret-joins-the-signed-in-account` without
 * `invitation-code-from-a-letter`): two constructor parameters, no code.
 */
class FakeMailOnlyInvitationEvent extends Event {
	public string $result = '';

	/**
	 * @param string $appId
	 * @param string $subjectRef
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $subjectRef,
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

		$this->assertSame(['status' => 'invited', 'subjectRef' => 'subject-1', 'invitation' => 'sent'], $result);

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

		// portal-guardian-invitation-mail: once linked, portaliq is asked to
		// mail the one-time link for that same account, under learniq's id.
		$this->assertCount(3, $this->dispatched);
		$mail = $this->dispatched[2];
		$this->assertInstanceOf(FakeInvitationEvent::class, $mail);
		$this->assertSame(['learniq', 'subject-1', 'mail'], [$mail->appId, $mail->subjectRef, $mail->channel]);

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
	 * portal-guardian-invitation-mail: a mail that did not leave, and a
	 * portaliq that refuses, are reported; the guardian stays linked.
	 *
	 * @return void
	 */
	public function testAMailThatDidNotLeaveIsReportedAndTheGuardianStaysLinked(): void {
		$notSent = $this->invitation(profile: $this->guardianProfile(), mailResult: 'not_sent')->invite(self::GUARDIAN, 'a@example.org', 'org');
		$refused = $this->invitation(profile: $this->guardianProfile(), mailResult: 'refused')->invite(self::GUARDIAN, 'a@example.org', 'org');
		$silent  = $this->invitation(profile: $this->guardianProfile(), mailResult: '')->invite(self::GUARDIAN, 'a@example.org', 'org');

		$this->assertSame(['status' => 'invited', 'subjectRef' => 'subject-1', 'invitation' => 'not-sent'], $notSent);
		$this->assertSame(['status' => 'invited', 'subjectRef' => 'subject-1', 'invitation' => 'unavailable'], $refused);
		$this->assertSame(['status' => 'invited', 'subjectRef' => 'subject-1', 'invitation' => 'unavailable'], $silent);
	}//end testAMailThatDidNotLeaveIsReportedAndTheGuardianStaysLinked()

	/**
	 * A portaliq from before the invitation event still links the guardian;
	 * no mail is asked for and the answer says so.
	 *
	 * @return void
	 */
	public function testAnOlderPortaliqLinksTheGuardianWithoutAMail(): void {
		$invitation = new GuardianPortalInvitation(
			$this->profiles(profile: $this->guardianProfile()),
			$this->dispatcher(),
			$this->createMock(LoggerInterface::class),
			FakeProvisionEvent::class,
			FakeClaimEvent::class,
			'OCA\\Portaliq\\Event\\NoSuchInvitationEvent'
		);

		$result = $invitation->invite(self::GUARDIAN, 'a@example.org', 'org');

		$this->assertSame(['status' => 'invited', 'subjectRef' => 'subject-1', 'invitation' => 'unavailable'], $result);
		$this->assertCount(2, $this->dispatched);
	}//end testAnOlderPortaliqLinksTheGuardianWithoutAMail()

	/**
	 * No mail is asked for when the claim did not land: an invitation for an
	 * account without the claim would show the guardian nothing.
	 *
	 * @return void
	 */
	public function testNoMailIsAskedForWhenTheClaimWasRefused(): void {
		$this->invitation(profile: $this->guardianProfile(), claimResult: 'refused')->invite(self::GUARDIAN, 'a@example.org', 'org');

		$this->assertCount(2, $this->dispatched);
	}//end testNoMailIsAskedForWhenTheClaimWasRefused()

	/**
	 * The event class this app names is the one portaliq ships.
	 *
	 * @return void
	 */
	public function testTheInvitationEventNamedIsPortaliqsOwn(): void {
		$this->assertSame('OCA\\Portaliq\\Event\\PortalAccountInvitationRequestedEvent', GuardianPortalInvitation::INVITATION_EVENT);
	}//end testTheInvitationEventNamedIsPortaliqsOwn()

	/**
	 * portal-guardian-invitation-letter: on the channel `letter` portaliq is
	 * asked for a code, and the answer carries it for the school to print.
	 *
	 * @return void
	 */
	public function testALetterAnswersTheCodeToPrint(): void {
		$result = $this->invitation(profile: $this->guardianProfile(), mailResult: 'code')
			->invite(self::GUARDIAN, 'a@example.org', 'org', GuardianPortalInvitation::CHANNEL_LETTER);

		$this->assertSame(
			['status' => 'invited', 'subjectRef' => 'subject-1', 'invitation' => 'code', 'code' => 'ABCD-EFGH-2345', 'expiresAt' => '2026-10-12T09:00:00+00:00'],
			$result
		);
		$asked = $this->dispatched[2];
		$this->assertInstanceOf(FakeInvitationEvent::class, $asked);
		$this->assertSame(['learniq', 'subject-1', 'letter'], [$asked->appId, $asked->subjectRef, $asked->channel]);
	}//end testALetterAnswersTheCodeToPrint()

	/**
	 * A mailed invitation never carries a code back, whatever portaliq says.
	 *
	 * @return void
	 */
	public function testAMailedInvitationCarriesNoCode(): void {
		$result = $this->invitation(profile: $this->guardianProfile())->invite(self::GUARDIAN, 'a@example.org', 'org');

		$this->assertArrayNotHasKey('code', $result);
		$this->assertArrayNotHasKey('expiresAt', $result);
	}//end testAMailedInvitationCarriesNoCode()

	/**
	 * A portaliq that refuses the code, answers none, or ships the event from
	 * before it had a channel: no code, and the guardian stays linked.
	 *
	 * @return void
	 */
	public function testALetterWithoutACodeSaysSoAndTheGuardianStaysLinked(): void {
		$expected = ['status' => 'invited', 'subjectRef' => 'subject-1', 'invitation' => 'unavailable'];

		$refused = $this->invitation(profile: $this->guardianProfile(), mailResult: 'refused')->invite(self::GUARDIAN, 'a@example.org', 'org', 'letter');
		$this->assertSame($expected, $refused);

		$older = new GuardianPortalInvitation(
			$this->profiles(profile: $this->guardianProfile()),
			$this->dispatcher(),
			$this->createMock(LoggerInterface::class),
			FakeProvisionEvent::class,
			FakeClaimEvent::class,
			FakeMailOnlyInvitationEvent::class
		);
		$this->assertSame($expected, $older->invite(self::GUARDIAN, 'a@example.org', 'org', 'letter'));

		$none = new GuardianPortalInvitation(
			$this->profiles(profile: $this->guardianProfile()),
			$this->dispatcher(),
			$this->createMock(LoggerInterface::class),
			FakeProvisionEvent::class,
			FakeClaimEvent::class,
			'OCA\\Portaliq\\Event\\NoSuchInvitationEvent'
		);
		$this->assertSame($expected, $none->invite(self::GUARDIAN, 'a@example.org', 'org', 'letter'));
	}//end testALetterWithoutACodeSaysSoAndTheGuardianStaysLinked()

	/**
	 * A channel that is neither mail nor letter is refused before anything
	 * is dispatched.
	 *
	 * @return void
	 */
	public function testAnUnknownChannelIsRefusedWithoutDispatching(): void {
		$result = $this->invitation(profile: $this->guardianProfile())->invite(self::GUARDIAN, 'a@example.org', 'org', 'sms');

		$this->assertSame(['status' => 'refused', 'reason' => 'channel-unknown'], $result);
		$this->assertSame([], $this->dispatched);
	}//end testAnUnknownChannelIsRefusedWithoutDispatching()

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
	 * @param string $mailResult What portaliq answers the invitation with.
	 *
	 * @return GuardianPortalInvitation
	 */
	private function invitation(?array $profile, string $claimResult='ok', string $mailResult='sent'): GuardianPortalInvitation {
		$this->dispatched = [];
		return new GuardianPortalInvitation(
			$this->profiles(profile: $profile),
			$this->dispatcher(claimResult: $claimResult, mailResult: $mailResult),
			$this->createMock(LoggerInterface::class),
			FakeProvisionEvent::class,
			FakeClaimEvent::class,
			FakeInvitationEvent::class
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
	 * @param string $mailResult What the invitation listener answers.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(string $claimResult='ok', string $mailResult='sent'): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($claimResult, $mailResult): void {
				$this->dispatched[] = $event;
				if ($event instanceof FakeProvisionEvent) {
					$event->subjectRef = 'subject-1';
				}

				if ($event instanceof FakeClaimEvent) {
					$event->result = $claimResult;
				}

				if ($event instanceof FakeInvitationEvent) {
					$event->result = $mailResult;
					if ($mailResult === 'code') {
						$event->code = 'ABCD-EFGH-2345';
						$event->expiresAt = '2026-10-12T09:00:00+00:00';
					}
				}
			}
		);
		return $dispatcher;
	}//end dispatcher()
}//end class
