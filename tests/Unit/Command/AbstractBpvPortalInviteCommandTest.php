<?php

/**
 * The shared run of the trainer and assessor portal invite commands.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Command
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

namespace OCA\Learniq\Tests\Unit\Command;

use OCA\Learniq\Command\AbstractBpvPortalInviteCommand;
use OCA\Learniq\Kernel\Contracts\BpvPortalInvitationInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * AbstractBpvPortalInviteCommand.
 *
 * @covers \OCA\Learniq\Command\AbstractBpvPortalInviteCommand
 */
class AbstractBpvPortalInviteCommandTest extends TestCase {

	/**
	 * A command over an invitation that answers the given result.
	 *
	 * @param array<string, mixed> $answer What invite() returns.
	 * @param array<string, mixed> $call   Receives the invite() arguments.
	 *
	 * @return Command
	 */
	private function command(array $answer, array &$call): Command {
		$invitations = $this->createMock(BpvPortalInvitationInterface::class);
		$invitations->expects($this->once())->method('invite')->willReturnCallback(
			function (string $role, string $personRef, string $organisation, string $email = '') use ($answer, &$call): array {
				$call = ['role' => $role, 'personRef' => $personRef, 'organisation' => $organisation, 'email' => $email];
				return $answer;
			}
		);

		return new class ($invitations) extends AbstractBpvPortalInviteCommand {
			protected function configure(): void {
				$this->setName(name: 'learniq:test:invite')
					->addArgument(name: 'someRef', mode: InputArgument::REQUIRED)
					->addArgument(name: 'organisation', mode: InputArgument::REQUIRED)
					->addArgument(name: 'email', mode: InputArgument::OPTIONAL, default: '');
			}

			protected function role(): string {
				return 'mentor';
			}

			protected function personArgument(): string {
				return 'someRef';
			}
		};
	}//end command()

	/**
	 * The subclass's role and person argument reach the invitation, and an
	 * invitation reports the portal account.
	 *
	 * @return void
	 */
	public function testTheSubclassRoleAndPersonAreInvited(): void {
		$call = [];
		$tester = new CommandTester($this->command(['status' => 'invited', 'subjectRef' => 'subject-9'], $call));

		$code = $tester->execute(['someRef' => 'p-1', 'organisation' => 'school', 'email' => 'a@example.org']);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertSame(['role' => 'mentor', 'personRef' => 'p-1', 'organisation' => 'school', 'email' => 'a@example.org'], $call);
		$this->assertSame("Invited. Portal account: subject-9\n", $tester->getDisplay());
	}//end testTheSubclassRoleAndPersonAreInvited()

	/**
	 * A refusal fails the command with its reason, or `unknown` without one.
	 *
	 * @return void
	 */
	public function testARefusalFailsWithItsReason(): void {
		$call = [];
		$tester = new CommandTester($this->command(['status' => 'refused', 'reason' => 'person-unknown'], $call));
		$this->assertSame(Command::FAILURE, $tester->execute(['someRef' => 'p-1', 'organisation' => 'school']));
		$this->assertSame("Not invited: person-unknown\n", $tester->getDisplay());
		$this->assertSame('', $call['email']);

		$tester = new CommandTester($this->command(['status' => 'refused'], $call));
		$this->assertSame(Command::FAILURE, $tester->execute(['someRef' => 'p-1', 'organisation' => 'school']));
		$this->assertSame("Not invited: unknown\n", $tester->getDisplay());
	}//end testARefusalFailsWithItsReason()
}//end class
