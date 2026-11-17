<?php

/**
 * Learniq reading of what an OpenRegister save changed, for a cascade listener.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener\Support
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
 *
 * @spec exclude Listener plumbing, not a behavioural requirement: it reads
 *       the two sides of a save so each cascade only says what to queue.
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener\Support;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;

/**
 * The saved object with the data it had before the save, and whether a set of
 * watched fields moved between the two.
 *
 * @spec exclude Listener plumbing, not a behavioural requirement.
 */
trait SavedObjectChange {

	/**
	 * The saved object and, for an update, the data before the save.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The event.
	 *
	 * @return array{0: \OCA\OpenRegister\Db\ObjectEntity, 1: array<string, mixed>}
	 */
	private static function sides(ObjectCreatedEvent|ObjectUpdatedEvent $event): array {
		if ($event instanceof ObjectUpdatedEvent === true) {
			return [$event->getNewObject(), ($event->getOldObject()?->getObject() ?? [])];
		}

		return [$event->getObject(), []];
	}//end sides()

	/**
	 * Whether none of the watched fields moved.
	 *
	 * @param array<int, string>   $fields The watched fields.
	 * @param array<string, mixed> $old    Before the save.
	 * @param array<string, mixed> $new    After the save.
	 *
	 * @return bool
	 */
	private static function unchanged(array $fields, array $old, array $new): bool {
		foreach ($fields as $field) {
			if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
				return false;
			}
		}

		return true;
	}//end unchanged()
}//end trait
