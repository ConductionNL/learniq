<?php

/**
 * Which rows a learnerRef back-fill still has to stamp.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair\Support
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

namespace OCA\Learniq\Tests\Unit\Repair\Support;

use OCA\Learniq\Repair\Support\MissingLearnerRef;
use PHPUnit\Framework\TestCase;

/**
 * MissingLearnerRef.
 *
 * @covers \OCA\Learniq\Repair\Support\MissingLearnerRef
 */
class MissingLearnerRefTest extends TestCase {

	/**
	 * A row without a learnerRef yields its learnerId and uuid, the id before the uuid.
	 *
	 * @return void
	 */
	public function testARowWithoutALearnerRefYieldsItsLearnerAndUuid(): void {
		$missing = new MissingLearnerRef();

		$this->assertSame(['jan', 'u-1'], $missing->inRow(row: ['learnerId' => 'jan', 'id' => 'u-1', 'uuid' => 'u-2']));
		$this->assertSame(['jan', 'u-2'], $missing->inRow(row: ['learnerId' => 'jan', 'uuid' => 'u-2']));
		$this->assertSame(['jan', 'u-1'], $missing->inRow(row: ['learnerId' => 'jan', 'id' => 'u-1', 'learnerRef' => '']));
		$this->assertSame(['jan', 'u-1'], $missing->inRow(row: ['learnerId' => 'jan', 'id' => 'u-1', 'learnerRef' => 7]));
	}//end testARowWithoutALearnerRefYieldsItsLearnerAndUuid()

	/**
	 * A row that already has a learnerRef, or lacks a learnerId or uuid, needs nothing.
	 *
	 * @return void
	 */
	public function testARowThatIsStampedOrIncompleteNeedsNothing(): void {
		$missing = new MissingLearnerRef();

		$this->assertNull($missing->inRow(row: ['learnerId' => 'jan', 'id' => 'u-1', 'learnerRef' => 'p-1']));
		$this->assertNull($missing->inRow(row: ['id' => 'u-1']));
		$this->assertNull($missing->inRow(row: ['learnerId' => '', 'id' => 'u-1']));
		$this->assertNull($missing->inRow(row: ['learnerId' => 12, 'id' => 'u-1']));
		$this->assertNull($missing->inRow(row: ['learnerId' => 'jan']));
		$this->assertNull($missing->inRow(row: ['learnerId' => 'jan', 'id' => '']));
		$this->assertNull($missing->inRow(row: ['learnerId' => 'jan', 'id' => 5]));
	}//end testARowThatIsStampedOrIncompleteNeedsNothing()
}//end class
