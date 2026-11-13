<?php

/**
 * Tests for the example-set load command.
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

use OCA\Learniq\Command\ExampleSetLoadCommand;
use OCA\Learniq\Portal\ExamplePortalAccountGrants;
use OCA\Learniq\Portal\ExamplePortalProvisioner;
use OCA\Learniq\Service\SeedProfileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command loads a set, reports its portal, provisions accounts and portal grants.
 *
 * @covers \OCA\Learniq\Command\ExampleSetLoadCommand
 * @uses   \OCA\Learniq\Command\ExampleSetPortalCommand
 */
class ExampleSetLoadCommandTest extends TestCase {

	/**
	 * A grant result that gave nothing.
	 */
	private const NO_GRANTS = ['status' => 'skipped'];

	/**
	 * Build the command.
	 *
	 * @param SeedProfileService              $sets     The sets.
	 * @param ExamplePortalProvisioner|null   $portals  The provisioner.
	 * @param ExamplePortalAccountGrants|null $grants   The grants.
	 *
	 * @return CommandTester
	 */
	private function tester(SeedProfileService $sets, ?ExamplePortalProvisioner $portals = null, ?ExamplePortalAccountGrants $grants = null): CommandTester {
		$portals ??= $this->portals(accounts: null);
		$grants ??= $this->grants(result: null);

		return new CommandTester(new ExampleSetLoadCommand($sets, $portals, $grants));
	}//end tester()

	/**
	 * A set service mock.
	 *
	 * @param bool                      $known   Whether the set is known.
	 * @param array<string, mixed>|null $result  The install result, null to throw.
	 *
	 * @return SeedProfileService
	 */
	private function sets(bool $known, ?array $result): SeedProfileService {
		$sets = $this->getMockBuilder(SeedProfileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['isKnown', 'install'])
			->getMock();
		$sets->method('isKnown')->with('mbo')->willReturn($known);
		if ($result === null) {
			$sets->method('install')->willThrowException(new RuntimeException('register missing'));
		} else {
			$sets->method('install')->with('mbo')->willReturn($result);
		}

		return $sets;
	}//end sets()

	/**
	 * A provisioner mock.
	 *
	 * @param array<string, int>|null $accounts The account result, null when it must not be asked.
	 *
	 * @return ExamplePortalProvisioner
	 */
	private function portals(?array $accounts): ExamplePortalProvisioner {
		$portals = $this->getMockBuilder(ExamplePortalProvisioner::class)
			->disableOriginalConstructor()
			->onlyMethods(['describe', 'provisionAccounts'])
			->getMock();
		$portals->method('describe')->willReturnCallback(static fn (array $result): string => 'Portal: ' . $result['status']);
		if ($accounts === null) {
			$portals->expects(self::never())->method('provisionAccounts');
		} else {
			$portals->expects(self::once())->method('provisionAccounts')->with('mbo')->willReturn($accounts);
		}

		return $portals;
	}//end portals()

	/**
	 * A grants mock.
	 *
	 * @param array<string, mixed>|null $result The grant result, null when it must not be asked.
	 *
	 * @return ExamplePortalAccountGrants
	 */
	private function grants(?array $result): ExamplePortalAccountGrants {
		$grants = $this->getMockBuilder(ExamplePortalAccountGrants::class)
			->disableOriginalConstructor()
			->onlyMethods(['grant'])
			->getMock();
		if ($result === null) {
			$grants->expects(self::never())->method('grant');
		} else {
			$grants->expects(self::once())->method('grant')->with('mbo')->willReturn($result);
		}

		return $grants;
	}//end grants()

	/**
	 * An unknown set fails before anything is installed.
	 *
	 * @return void
	 */
	public function testAnUnknownSetFails(): void {
		$sets = $this->sets(known: false, result: []);
		$sets->expects(self::never())->method('install');
		$tester = $this->tester(sets: $sets);

		self::assertSame(Command::FAILURE, $tester->execute(['set' => 'mbo']));
		self::assertSame("Unknown example set \"mbo\".\n", $tester->getDisplay());
	}//end testAnUnknownSetFails()

	/**
	 * A failing install fails with its message.
	 *
	 * @return void
	 */
	public function testAFailingInstallFails(): void {
		$tester = $this->tester(sets: $this->sets(known: true, result: null));

		self::assertSame(Command::FAILURE, $tester->execute(['set' => 'mbo']));
		self::assertSame("Example set \"mbo\" was not loaded: register missing\n", $tester->getDisplay());
	}//end testAFailingInstallFails()

	/**
	 * --no-accounts loads and reports without touching accounts or grants.
	 *
	 * @return void
	 */
	public function testNoAccountsSkipsAccountsAndGrants(): void {
		$tester = $this->tester(sets: $this->sets(known: true, result: ['objects' => 12, 'skipped' => 3]));

		self::assertSame(Command::SUCCESS, $tester->execute(['set' => 'mbo', '--no-accounts' => true]));
		self::assertSame("Example set mbo: 12 objects in the set, 3 skipped.\n", $tester->getDisplay());
	}//end testNoAccountsSkipsAccountsAndGrants()

	/**
	 * Known portal statuses keep success; an unexpected one fails, and is still described.
	 *
	 * @return void
	 */
	public function testThePortalStatusDecidesTheExit(): void {
		foreach (['created' => Command::SUCCESS, 'unmapped' => Command::SUCCESS, 'portaliq-absent' => Command::SUCCESS, 'failed' => Command::FAILURE] as $status => $exit) {
			$tester = $this->tester(sets: $this->sets(known: true, result: ['objects' => 1, 'skipped' => 0, 'portal' => ['status' => $status]]));
			self::assertSame($exit, $tester->execute(['set' => 'mbo', '--no-accounts' => true]), $status);
			self::assertStringContainsString('Portal: ' . $status . "\n", $tester->getDisplay());
		}
	}//end testThePortalStatusDecidesTheExit()

	/**
	 * Accounts and grants are provisioned and reported; a failed account fails the run.
	 *
	 * @return void
	 */
	public function testAccountsAndGrantsAreReported(): void {
		$tester = $this->tester(
			sets: $this->sets(known: true, result: ['objects' => 5, 'skipped' => 0]),
			portals: $this->portals(accounts: ['created' => 2, 'named' => 1, 'kept' => 4, 'failed' => 0]),
			grants: $this->grants(result: ['status' => 'done', 'granted' => 3, 'kept' => 1, 'failed' => 0, 'waiting' => 0, 'reasons' => []])
		);

		self::assertSame(Command::SUCCESS, $tester->execute(['set' => 'mbo']));
		self::assertSame(
			"Example set mbo: 5 objects in the set, 0 skipped.\nAccounts: 2 created, 1 named, 4 kept, 0 failed.\nPortal accounts: 3 given, 1 kept, 0 failed.\n",
			$tester->getDisplay()
		);

		$tester = $this->tester(
			sets: $this->sets(known: true, result: ['objects' => 5, 'skipped' => 0]),
			portals: $this->portals(accounts: ['created' => 0, 'named' => 0, 'kept' => 0, 'failed' => 1]),
			grants: $this->grants(result: self::NO_GRANTS)
		);
		self::assertSame(Command::FAILURE, $tester->execute(['set' => 'mbo']));
		self::assertStringEndsWith("Accounts: 0 created, 0 named, 0 kept, 1 failed.\nPortal accounts: none to give.\n", $tester->getDisplay());
	}//end testAccountsAndGrantsAreReported()

	/**
	 * The grant summary words each outcome.
	 *
	 * @return void
	 */
	public function testDescribeGrantsWordsEachOutcome(): void {
		self::assertSame(
			'Portal accounts: portaliq is too old to give a Nextcloud user a portal account; update portaliq and load the set again.',
			ExampleSetLoadCommand::describeGrants(grants: ['status' => 'portaliq-too-old'])
		);
		self::assertSame('Portal accounts: none to give.', ExampleSetLoadCommand::describeGrants(grants: ['status' => 'no-portal']));
		self::assertSame(
			"Portal accounts: 1 given, 2 kept, 3 failed. 4 wait for the portal's organisation: set it, then load the set again. Refused: no-email, duplicate.",
			ExampleSetLoadCommand::describeGrants(grants: ['status' => 'done', 'granted' => 1, 'kept' => 2, 'failed' => 3, 'waiting' => 4, 'reasons' => ['no-email', 'duplicate']])
		);
	}//end testDescribeGrantsWordsEachOutcome()
}//end class
