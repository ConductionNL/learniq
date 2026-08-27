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
 * check itself errors.
 *
 * The guard is scoped to a PENDING rename: the destination slug existing on an
 * already-renamed install is the expected end state, not a collision. The step
 * used to warn "manual investigation required" on every upgrade after the
 * rename had completed — a false alarm this suite pins down as a no-op info.
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
	 * The step counts rows per slug: the first executeQuery counts the OLD
	 * slug ('scholiq', is a rename pending?), the second counts the NEW slug
	 * ('learniq', would it collide?). The double dispatches on the bound slug.
	 *
	 * @param int|DbException $oldSlugCount Rows still using the old slug, or the error the count throws.
	 * @param int|DbException $newSlugCount Rows already using the new slug, or the error the count throws.
	 * @param int             $updatedRows  Rows the UPDATE reports changing.
	 * @param int|null        $updateCalls  Receives how many times executeStatement ran.
	 *
	 * @return RenameRegisterSlug
	 */
	private function stepWith(
		int|DbException $oldSlugCount,
		int|DbException $newSlugCount,
		int $updatedRows,
		?int &$updateCalls,
	): RenameRegisterSlug {
		$updateCalls = 0;
		$db = $this->createMock(IDBConnection::class);

		$resultFor = function (int|DbException $count): IResult {
			if ($count instanceof DbException) {
				throw $count;
			}

			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturn($count);
			return $result;
		};

		$db->method('executeQuery')->willReturnCallback(
			static function (string $sql, array $params) use ($resultFor, $oldSlugCount, $newSlugCount): IResult {
				return $resultFor(($params[0] ?? null) === 'scholiq' ? $oldSlugCount : $newSlugCount);
			}
		);

		$db->method('executeStatement')->willReturnCallback(
			static function () use (&$updateCalls, $updatedRows): int {
				$updateCalls++;
				return $updatedRows;
			}
		);

		return new RenameRegisterSlug($db, $this->createMock(LoggerInterface::class));
	}//end stepWith()

	/**
	 * With a pending rename and no collision the rename runs and reports the row count.
	 *
	 * @return void
	 */
	public function testRenamesWhenAPendingRenameHasNoCollision(): void {
		$calls = null;
		$step = $this->stepWith(1, 0, 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())
			->method('info')
			->with(self::stringContains('1 register(s) renamed'));
		$output->expects(self::never())->method('warning');

		$step->run($output);

		self::assertSame(1, $calls, 'The UPDATE must run exactly once.');
	}//end testRenamesWhenAPendingRenameHasNoCollision()

	/**
	 * An existing `learniq` register aborts a PENDING rename rather than merging.
	 *
	 * @return void
	 */
	public function testRefusesToRenameWhenTheTargetSlugAlreadyExists(): void {
		$calls = null;
		$step = $this->stepWith(1, 1, 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');
		$output->expects(self::never())->method('info');

		$step->run($output);

		self::assertSame(0, $calls, 'Merging two registers is unrecoverable; the step must not UPDATE.');
	}//end testRefusesToRenameWhenTheTargetSlugAlreadyExists()

	/**
	 * An already-renamed install (no `scholiq` row, `learniq` present) is a
	 * quiet no-op — the destination slug existing is the expected end state,
	 * not a collision, and repeating a warning on every upgrade is the exact
	 * false alarm this step used to produce.
	 *
	 * @return void
	 */
	public function testStaysQuietWhenTheRenameHasAlreadyHappened(): void {
		$calls = null;
		$step = $this->stepWith(0, 1, 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('warning');
		$output->expects(self::once())
			->method('info')
			->with(self::stringContains('nothing to rename'));

		$step->run($output);

		self::assertSame(0, $calls, 'A completed rename must not UPDATE again.');
	}//end testStaysQuietWhenTheRenameHasAlreadyHappened()

	/**
	 * A failing pending-rename check fails CLOSED — it does not fall through
	 * to the rename. An unreadable guard must never read as "no collision".
	 *
	 * @return void
	 */
	public function testFailsClosedWhenThePendingCheckItselfErrors(): void {
		$calls = null;
		$step = $this->stepWith(new DbException('database unavailable'), 0, 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$step->run($output);

		self::assertSame(0, $calls, 'An erroring guard must deny, not permit.');
	}//end testFailsClosedWhenThePendingCheckItselfErrors()

	/**
	 * A failing collision check fails CLOSED too, even when a rename is pending.
	 *
	 * @return void
	 */
	public function testFailsClosedWhenTheCollisionCheckItselfErrors(): void {
		$calls = null;
		$step = $this->stepWith(1, new DbException('database unavailable'), 1, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$step->run($output);

		self::assertSame(0, $calls, 'An erroring guard must deny, not permit.');
	}//end testFailsClosedWhenTheCollisionCheckItselfErrors()

	/**
	 * Re-running after a completed rename changes nothing and stays quiet:
	 * the pending check finds no `scholiq` row, so the step is idempotent.
	 *
	 * @return void
	 */
	public function testIsIdempotentOnAnAlreadyRenamedInstall(): void {
		$calls = null;
		$step = $this->stepWith(0, 1, 0, $calls);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())
			->method('info')
			->with(self::stringContains('nothing to rename'));
		$output->expects(self::never())->method('warning');

		$step->run($output);

		self::assertSame(0, $calls);
	}//end testIsIdempotentOnAnAlreadyRenamedInstall()

	/**
	 * The step names itself, so a repair run is readable.
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheRename(): void {
		$calls = null;
		$name = $this->stepWith(0, 0, 0, $calls)->getName();

		self::assertStringContainsString('scholiq', $name);
		self::assertStringContainsString('learniq', $name);
	}//end testGetNameDescribesTheRename()
}//end class
