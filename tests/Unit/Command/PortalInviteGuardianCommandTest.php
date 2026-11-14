<?php

/**
 * Tests for the guardian portal invitation command.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Command
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

namespace OCA\Learniq\Tests\Unit\Command;

use OCA\Learniq\Command\PortalInviteGuardianCommand;
use OCA\Learniq\Portal\GuardianPortalInvitation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command passes its arguments and channel to the invitation and words the outcome.
 *
 * @covers \OCA\Learniq\Command\PortalInviteGuardianCommand
 */
class PortalInviteGuardianCommandTest extends TestCase {

	/**
	 * What the invitation received.
	 *
	 * @var array<string, string>
	 */
	private array $received = [];

	/**
	 * Run the command against an invitation that answers $answer.
	 *
	 * @param array<string, mixed> $answer  The invitation's result.
	 * @param array<string, mixed> $options Extra command input.
	 *
	 * @return CommandTester
	 */
	private function invite(array $answer, array $options = []): CommandTester {
		$invitation = $this->getMockBuilder(GuardianPortalInvitation::class)
			->disableOriginalConstructor()
			->onlyMethods(['invite'])
			->getMock();
		$invitation->expects(self::once())->method('invite')->willReturnCallback(
			function (string $guardianRef, string $email, string $organisation, string $channel, string $issuedBy) use ($answer): array {
				$this->received = compact('guardianRef', 'email', 'organisation', 'channel', 'issuedBy');
				return $answer;
			}
		);

		$tester = new CommandTester(new PortalInviteGuardianCommand($invitation));
		$tester->execute(array_merge(['guardianRef' => 'lp-g1', 'email' => 'ouder@example.org', 'organisation' => 'school-ouders'], $options));

		return $tester;
	}//end invite()

	/**
	 * A mailed invitation passes the mail channel and says the link was sent.
	 *
	 * @return void
	 */
	public function testAMailedInvitationSaysTheLinkWasSent(): void {
		$tester = $this->invite(answer: ['status' => 'invited', 'subjectRef' => 'subj-1', 'invitation' => GuardianPortalInvitation::MAIL_SENT]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame(
			['guardianRef' => 'lp-g1', 'email' => 'ouder@example.org', 'organisation' => 'school-ouders', 'channel' => GuardianPortalInvitation::CHANNEL_MAIL, 'issuedBy' => 'occ'],
			$this->received
		);
		self::assertSame(
			"Invited. Portal account: subj-1\nThe portal mailed the guardian a link. It works once, for seven days.\n",
			$tester->getDisplay()
		);
	}//end testAMailedInvitationSaysTheLinkWasSent()

	/**
	 * A mail that did not leave is reported, still as a success.
	 *
	 * @return void
	 */
	public function testAMailThatDidNotLeaveIsReported(): void {
		$tester = $this->invite(answer: ['status' => 'invited', 'subjectRef' => 'subj-1', 'invitation' => GuardianPortalInvitation::MAIL_NOT_SENT]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('The invitation mail did not leave. Check the mail settings and invite again.', $tester->getDisplay());
	}//end testAMailThatDidNotLeaveIsReported()

	/**
	 * An unknown or missing invitation kind falls back to the "no invitation" line.
	 *
	 * @return void
	 */
	public function testAnUnknownInvitationKindFallsBackToUnavailable(): void {
		$tester = $this->invite(answer: ['status' => 'invited']);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame(
			"Invited. Portal account: \nNo invitation was sent: this portal does not make one. The guardian is still linked on the verified address.\n",
			$tester->getDisplay()
		);
	}//end testAnUnknownInvitationKindFallsBackToUnavailable()

	/**
	 * --letter asks for the letter channel and prints the code and its expiry.
	 *
	 * @return void
	 */
	public function testTheLetterOptionPrintsTheCode(): void {
		$tester = $this->invite(
			answer: ['status' => 'invited', 'subjectRef' => 'subj-2', 'invitation' => GuardianPortalInvitation::LETTER_CODE, 'code' => 'ABCD-1234', 'expiresAt' => '2026-12-01'],
			options: ['--letter' => true]
		);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame(GuardianPortalInvitation::CHANNEL_LETTER, $this->received['channel']);
		self::assertSame(
			"Invited. Portal account: subj-2\nCode for the letter: ABCD-1234\n"
			. "It works once, until 2026-12-01. The guardian signs in and types it under \"My account\".\n",
			$tester->getDisplay()
		);
	}//end testTheLetterOptionPrintsTheCode()

	/**
	 * A refusal fails with its reason, or "unknown" without one.
	 *
	 * @return void
	 */
	public function testARefusalFailsWithItsReason(): void {
		$tester = $this->invite(answer: ['status' => 'refused', 'reason' => 'email-not-verified']);
		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertSame("Not invited: email-not-verified\n", $tester->getDisplay());

		$tester = $this->invite(answer: ['status' => 'refused']);
		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertSame("Not invited: unknown\n", $tester->getDisplay());
	}//end testARefusalFailsWithItsReason()
}//end class
