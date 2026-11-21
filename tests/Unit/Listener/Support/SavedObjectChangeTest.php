<?php

/**
 * The two sides of a save and whether watched fields moved between them.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener\Support
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

namespace OCA\Learniq\Tests\Unit\Listener\Support;

use OCA\Learniq\Listener\Support\SavedObjectChange;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * SavedObjectChange.
 *
 * @covers \OCA\Learniq\Listener\Support\SavedObjectChange
 */
class SavedObjectChangeTest extends TestCase {

	/**
	 * A host exposing the trait's private steps.
	 *
	 * @return object
	 */
	private static function host(): object {
		return new class {
			use SavedObjectChange;

			public function sidesOf(ObjectCreatedEvent|ObjectUpdatedEvent $event): array {
				return self::sides(event: $event);
			}

			public function isUnchanged(array $fields, array $old, array $new): bool {
				return self::unchanged(fields: $fields, old: $old, new: $new);
			}
		};
	}//end host()

	/**
	 * An update hands back the new object and the data before the save.
	 *
	 * @return void
	 */
	public function testAnUpdateHasTheNewObjectAndTheOldData(): void {
		$new = OrEntityFactory::make(['lifecycle' => 'confirmed'], 'enrolment', 'learniq', 'row-1');
		$old = OrEntityFactory::make(['lifecycle' => 'pending'], 'enrolment', 'learniq', 'row-1');

		[$entity, $before] = self::host()->sidesOf(new ObjectUpdatedEvent($new, $old));

		$this->assertSame($new, $entity);
		$this->assertSame('pending', $before['lifecycle']);
	}//end testAnUpdateHasTheNewObjectAndTheOldData()

	/**
	 * A create hands back the object and no data before it.
	 *
	 * @return void
	 */
	public function testACreateHasNoDataBefore(): void {
		$created = OrEntityFactory::make(['lifecycle' => 'pending'], 'enrolment', 'learniq', 'row-2');

		[$entity, $before] = self::host()->sidesOf(new ObjectCreatedEvent($created));

		$this->assertSame($created, $entity);
		$this->assertSame([], $before);
	}//end testACreateHasNoDataBefore()

	/**
	 * Only the watched fields count: a moved field reports a change, an
	 * unwatched one does not, and a field that appears or disappears does.
	 *
	 * @return void
	 */
	public function testOnlyWatchedFieldsCount(): void {
		$host = self::host();

		$this->assertTrue($host->isUnchanged(['a', 'b'], ['a' => 1, 'b' => 'x', 'c' => 1], ['a' => 1, 'b' => 'x', 'c' => 2]));
		$this->assertFalse($host->isUnchanged(['a', 'b'], ['a' => 1, 'b' => 'x'], ['a' => 1, 'b' => 'y']));
		$this->assertFalse($host->isUnchanged(['a'], [], ['a' => 1]));
		$this->assertFalse($host->isUnchanged(['a'], ['a' => '1'], ['a' => 1]));
		$this->assertTrue($host->isUnchanged([], ['a' => 1], ['a' => 2]));
	}//end testOnlyWatchedFieldsCount()
}//end class
