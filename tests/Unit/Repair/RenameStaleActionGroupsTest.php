<?php

/**
 * RenameStaleActionGroups fixes a stored matrix once (live pass D1).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\RenameStaleActionGroups;
use OCA\Learniq\Service\ActionAuthService;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The stored matrix of an install seeded before the fix.
 */
class RenameStaleActionGroupsTest extends TestCase {

	/**
	 * The matrix the old seed wrote.
	 */
	private const STORED = [
		'compliance.department-rollup' => ['admin', 'compliance-officer', 'hr', 'manager'],
		'regulation.assign' => ['admin', 'compliance-officer', 'compliance-officers', 'hr'],
		'qti.import' => ['admin'],
		'broken' => 'not-a-list',
	];

	/**
	 * The step over a matrix, with the groups that exist.
	 *
	 * @param array<int,string> $existing Group ids that exist.
	 * @param array<string,mixed>|null $written Receives the written matrix.
	 * @param bool $failWrite Whether setMatrix throws.
	 *
	 * @return RenameStaleActionGroups
	 */
	private function step(array $existing, ?array &$written, bool $failWrite = false): RenameStaleActionGroups {
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('getMatrix')->willReturn(self::STORED);
		$auth->method('setMatrix')->willReturnCallback(
			static function (array $matrix) use (&$written, $failWrite): void {
				if ($failWrite === true) {
					throw new RuntimeException('read-only');
				}

				$written = $matrix;
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, $existing, true));

		return new RenameStaleActionGroups($auth, $groups);
	}//end step()

	/**
	 * Both stale names become their groups, without duplicates; other rows stay.
	 *
	 * @return void
	 */
	public function testRenamesTheStaleNames(): void {
		$written = null;
		$step = $this->step(existing: [], written: $written);
		self::assertNotSame('', $step->getName());
		$step->run($this->createMock(IOutput::class));

		self::assertSame(['admin', 'compliance-officers', 'hr', 'administration-managers'], $written['compliance.department-rollup']);
		self::assertSame(['admin', 'compliance-officers', 'hr'], $written['regulation.assign']);
		self::assertSame(['admin'], $written['qti.import']);
		self::assertSame('not-a-list', $written['broken']);
	}//end testRenamesTheStaleNames()

	/**
	 * A name that is a real group was an admin's choice and stays; nothing to
	 * change writes nothing.
	 *
	 * @return void
	 */
	public function testARealGroupStays(): void {
		$written = null;
		$step = $this->step(existing: ['compliance-officer', 'manager'], written: $written);
		$step->run($this->createMock(IOutput::class));
		self::assertNull($written);
	}//end testARealGroupStays()

	/**
	 * A matrix that cannot be written is reported, not thrown.
	 *
	 * @return void
	 */
	public function testAFailedWriteWarns(): void {
		$written = null;
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');
		$this->step(existing: [], written: $written, failWrite: true)->run($output);
	}//end testAFailedWriteWarns()
}//end class
