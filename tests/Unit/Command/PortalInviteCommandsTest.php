<?php

/**
 * Tests for the two BPV portal invitation commands.
 *
 * Each command exists to hand one role and one uuid to the invitation, so the
 * tests assert exactly that: the role the command names, the arguments it
 * passes through, and the exit code it answers with when the invitation
 * refuses.
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
 *
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Command;

use OCA\Learniq\Command\PortalInviteAssessorCommand;
use OCA\Learniq\Command\PortalInviteTrainerCommand;
use OCA\Learniq\Portal\BpvPortalInvitation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The commands pass the role and the uuid through, and report a refusal.
 */
class PortalInviteCommandsTest extends TestCase {

	private const TRAINER = 'ee030010-0000-4000-8000-000000000001';

	private const ASSESSOR = 'ee030031-0000-4000-8000-000000000001';

	/**
	 * What the invitation was called with on the last run.
	 *
	 * @var array<string, mixed>
	 */
	private array $received = [];

	/**
	 * An invitation double that records its arguments and answers as told.
	 *
	 * `onlyMethods` is deliberate: it refuses a method the real class lacks,
	 * where `addMethods` would invent one and the test could only pass.
	 *
	 * @param array{status: string, reason?: string, subjectRef?: string} $answer What it answers.
	 *
	 * @return BpvPortalInvitation
	 */
	private function invitation(array $answer): BpvPortalInvitation {
		$this->received = [];
		$double = $this->getMockBuilder(BpvPortalInvitation::class)
			->disableOriginalConstructor()
			->onlyMethods(['invite'])
			->getMock();
		$double->method('invite')->willReturnCallback(
			function (string $role, string $personRef, string $organisation, string $email = '') use ($answer): array {
				$this->received = [
					'role' => $role,
					'personRef' => $personRef,
					'organisation' => $organisation,
					'email' => $email,
				];
				return $answer;
			}
		);

		return $double;
	}//end invitation()

	/**
	 * The trainer command invites the trainer role and passes its arguments on.
	 *
	 * @return void
	 */
	public function testTheTrainerCommandInvitesTheTrainerRole(): void {
		$tester = new CommandTester(new PortalInviteTrainerCommand($this->invitation(['status' => 'invited', 'subjectRef' => 'subject-1'])));

		$code = $tester->execute(
			[
				'praktijkopleiderRef' => self::TRAINER,
				'organisation' => 'esdoorn',
				'email' => 'karin.smit@vandam.example',
			]
		);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			[
				'role' => 'trainer',
				'personRef' => self::TRAINER,
				'organisation' => 'esdoorn',
				'email' => 'karin.smit@vandam.example',
			],
			$this->received
		);
		self::assertStringContainsString('subject-1', $tester->getDisplay());
	}//end testTheTrainerCommandInvitesTheTrainerRole()

	/**
	 * The assessor command invites the assessor role, and without the optional
	 * address argument it passes an empty one, so the row's own is used.
	 *
	 * @return void
	 */
	public function testTheAssessorCommandInvitesTheAssessorRole(): void {
		$tester = new CommandTester(new PortalInviteAssessorCommand($this->invitation(['status' => 'invited', 'subjectRef' => 'subject-2'])));

		$code = $tester->execute(
			[
				'externalAssessorRef' => self::ASSESSOR,
				'organisation' => 'vaartdam',
			]
		);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			[
				'role' => 'assessor',
				'personRef' => self::ASSESSOR,
				'organisation' => 'vaartdam',
				'email' => '',
			],
			$this->received
		);
	}//end testTheAssessorCommandInvitesTheAssessorRole()

	/**
	 * A refusal is a failing exit code and names its reason, so a script that
	 * invites in bulk stops rather than reporting success.
	 *
	 * @return void
	 */
	public function testARefusalFailsAndNamesItsReason(): void {
		$trainer = new CommandTester(new PortalInviteTrainerCommand($this->invitation(['status' => 'refused', 'reason' => 'person-unknown'])));
		$trainerCode = $trainer->execute(['praktijkopleiderRef' => self::TRAINER, 'organisation' => 'esdoorn']);

		self::assertSame(Command::FAILURE, $trainerCode);
		self::assertStringContainsString('person-unknown', $trainer->getDisplay());

		$assessor = new CommandTester(new PortalInviteAssessorCommand($this->invitation(['status' => 'refused', 'reason' => 'email-invalid'])));
		$assessorCode = $assessor->execute(['externalAssessorRef' => self::ASSESSOR, 'organisation' => 'vaartdam']);

		self::assertSame(Command::FAILURE, $assessorCode);
		self::assertStringContainsString('email-invalid', $assessor->getDisplay());
	}//end testARefusalFailsAndNamesItsReason()

	/**
	 * Both commands carry the names `appinfo/info.xml` registers, and the
	 * arguments the invitation needs are required, not optional.
	 *
	 * @return void
	 */
	public function testTheCommandNamesAndRequiredArgumentsAreTheRegisteredOnes(): void {
		$trainer = new PortalInviteTrainerCommand($this->invitation(['status' => 'invited']));
		$assessor = new PortalInviteAssessorCommand($this->invitation(['status' => 'invited']));

		self::assertSame('learniq:portal:invite-trainer', $trainer->getName());
		self::assertSame('learniq:portal:invite-assessor', $assessor->getName());

		self::assertTrue($trainer->getDefinition()->getArgument('praktijkopleiderRef')->isRequired());
		self::assertTrue($trainer->getDefinition()->getArgument('organisation')->isRequired());
		self::assertFalse($trainer->getDefinition()->getArgument('email')->isRequired());
		self::assertTrue($assessor->getDefinition()->getArgument('externalAssessorRef')->isRequired());
		self::assertTrue($assessor->getDefinition()->getArgument('organisation')->isRequired());
		self::assertFalse($assessor->getDefinition()->getArgument('email')->isRequired());

		$registered = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		self::assertStringContainsString(PortalInviteTrainerCommand::class, $registered);
		self::assertStringContainsString(PortalInviteAssessorCommand::class, $registered);
	}//end testTheCommandNamesAndRequiredArgumentsAreTheRegisteredOnes()
}//end class
