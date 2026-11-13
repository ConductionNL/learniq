<?php

/**
 * Tests for the example-set portal command.
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

use OCA\Learniq\Command\ExampleSetPortalCommand;
use OCA\Learniq\Portal\ExamplePortalProvisioner;
use OCA\Learniq\Service\ExampleSetDates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command provisions the portal with the set's date offset and exits by status.
 *
 * @covers \OCA\Learniq\Command\ExampleSetPortalCommand
 */
class ExampleSetPortalCommandTest extends TestCase {

	/**
	 * A provisioner that expects one provision call and answers $status.
	 *
	 * @param string $status The provision status.
	 * @param int    $days   The expected day offset.
	 *
	 * @return ExamplePortalProvisioner
	 */
	private function portals(string $status, int $days): ExamplePortalProvisioner {
		$portals = $this->getMockBuilder(ExamplePortalProvisioner::class)
			->disableOriginalConstructor()
			->onlyMethods(['provision', 'describe'])
			->getMock();
		$portals->expects(self::once())->method('provision')->with('mbo', $days, $days)->willReturn(['status' => $status]);
		$portals->expects(self::once())->method('describe')->with(['status' => $status])->willReturn('Portal: ' . $status);

		return $portals;
	}//end portals()

	/**
	 * A date service mock.
	 *
	 * @param int|null $applied The set's applied offset.
	 * @param int      $current The current offset.
	 *
	 * @return ExampleSetDates
	 */
	private function dates(?int $applied, int $current): ExampleSetDates {
		$dates = $this->getMockBuilder(ExampleSetDates::class)
			->disableOriginalConstructor()
			->onlyMethods(['appliedOffset', 'currentOffset'])
			->getMock();
		$dates->method('appliedOffset')->with('mbo')->willReturn($applied);
		$dates->method('currentOffset')->willReturn($current);

		return $dates;
	}//end dates()

	/**
	 * Every success status exits 0 and prints the provisioner's description.
	 *
	 * @return void
	 */
	public function testSuccessStatusesSucceed(): void {
		foreach (ExampleSetPortalCommand::SUCCESS_STATUSES as $status) {
			$tester = new CommandTester(new ExampleSetPortalCommand($this->portals(status: $status, days: 0)));
			self::assertSame(Command::SUCCESS, $tester->execute(['set' => 'mbo']), $status);
			self::assertSame('Portal: ' . $status . "\n", $tester->getDisplay());
		}
	}//end testSuccessStatusesSucceed()

	/**
	 * Any other status fails.
	 *
	 * @return void
	 */
	public function testOtherStatusesFail(): void {
		$tester = new CommandTester(new ExampleSetPortalCommand($this->portals(status: 'portaliq-absent', days: 0)));

		self::assertSame(Command::FAILURE, $tester->execute(['set' => 'mbo']));
		self::assertSame("Portal: portaliq-absent\n", $tester->getDisplay());
	}//end testOtherStatusesFail()

	/**
	 * The set's applied offset wins; without one the current offset is used.
	 *
	 * @return void
	 */
	public function testTheSetsDateOffsetIsPassedOn(): void {
		$tester = new CommandTester(new ExampleSetPortalCommand($this->portals(status: 'created', days: 14), $this->dates(applied: 14, current: 30)));
		self::assertSame(Command::SUCCESS, $tester->execute(['set' => 'mbo']));

		$tester = new CommandTester(new ExampleSetPortalCommand($this->portals(status: 'created', days: 30), $this->dates(applied: null, current: 30)));
		self::assertSame(Command::SUCCESS, $tester->execute(['set' => 'mbo']));
	}//end testTheSetsDateOffsetIsPassedOn()
}//end class
