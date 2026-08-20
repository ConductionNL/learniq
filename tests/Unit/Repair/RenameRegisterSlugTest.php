<?php

/**
 * Unit tests for RenameRegisterSlug.
 *
 * Why the collision guard matters more than the rename: object storage is
 * sharded into `oc_openregister_table_{registerId}_{schemaId}` tables keyed on
 * the register's NUMERIC id, never its slug — verified on a live instance,
 * where `SELECT COUNT(*) FROM information_schema.tables WHERE table_name ~
 * 'oc_openregister_table_[a-z]'` returns 0. So the rename is a single-row
 * UPDATE and cannot orphan objects. What it CAN do is collide with an existing
 * `learniq` register, and merging two registers silently would be the
 * unrecoverable outcome. The guard therefore fails CLOSED, including when the
 * collision check itself errors.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\RenameRegisterSlug;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Repair\RenameRegisterSlug
 */
class RenameRegisterSlugTest extends TestCase {
	/**
	 * Build the step over a database double.
	 *
	 * @param int|DbException $collisionCount Rows already using the new slug, or the error the check throws.
	 * @param int             $updatedRows    Rows the UPDATE reports changing.
	 * @param int|null        $updateCalls    Receives how many times executeStatement ran.
	 *
	 * @return RenameRegisterSlug
	 */
	private function stepWith(int|DbException $collisionCount, int $updatedRows, ?int &$updateCalls): RenameRegisterSlug {
		$updateCalls = 0;
		$db = $this->createMock(IDBConnection::class);

		if ($collisionCount instanceof DbException) {
			$db->method('executeQuery')->willThrowException($collisionCount);
		} else {
			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturn($collisionCount);
			$db->method('executeQuery')->willReturn($result);
		}

		$db->method('executeStatement')->willReturnCallback(
			static function () use (&$updateCalls, $updatedRows): int {
				$updateCalls++;
				return $updatedRows;
			}
		);

		return new RenameRegisterSlug($db, $this->createMock(LoggerInterface::class));
	}//end stepWith()

	/**
	 * With no collision the rename runs and reports the row count.
	 *
	 * @return void
	 */
	public function testRenamesWhenNoCollisionExists(): void {
		$calls = null;
		$step = $this->stepWith(0, 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())
			->method('info')
			->with(self::stringContains('1 register(s) renamed'));

		$step->run($output);

		self::assertSame(1, $calls, 'The UPDATE must run exactly once.');
	}//end testRenamesWhenNoCollisionExists()

	/**
	 * An existing `learniq` register aborts the rename rather than merging.
	 *
	 * @return void
	 */
	public function testRefusesToRenameWhenTheTargetSlugAlreadyExists(): void {
		$calls = null;
		$step = $this->stepWith(1, 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');
		$output->expects(self::never())->method('info');

		$step->run($output);

		self::assertSame(0, $calls, 'Merging two registers is unrecoverable; the step must not UPDATE.');
	}//end testRefusesToRenameWhenTheTargetSlugAlreadyExists()

	/**
	 * A failing collision check fails CLOSED — it does not fall through to the
	 * rename. An unreadable guard must never read as "no collision".
	 *
	 * @return void
	 */
	public function testFailsClosedWhenTheCollisionCheckItselfErrors(): void {
		$calls = null;
		$step = $this->stepWith(new DbException('database unavailable'), 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$step->run($output);

		self::assertSame(0, $calls, 'An erroring guard must deny, not permit.');
	}//end testFailsClosedWhenTheCollisionCheckItselfErrors()

	/**
	 * Re-running after a completed rename changes nothing: the UPDATE matches
	 * no rows, so the step is idempotent.
	 *
	 * @return void
	 */
	public function testIsIdempotentOnAnAlreadyRenamedInstall(): void {
		$calls = null;
		$step = $this->stepWith(0, 0, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())
			->method('info')
			->with(self::stringContains('0 register(s) renamed'));

		$step->run($output);

		self::assertSame(1, $calls);
	}//end testIsIdempotentOnAnAlreadyRenamedInstall()

	/**
	 * The step names itself, so a repair run is readable.
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheRename(): void {
		$calls = null;
		$name = $this->stepWith(0, 0, $calls)->getName();

		self::assertStringContainsString('scholiq', $name);
		self::assertStringContainsString('learniq', $name);
	}//end testGetNameDescribesTheRename()
}//end class
