<?php

/**
 * Learniq object a write event carries.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec exclude Listener plumbing, not a behavioural requirement: which
 *       object a create or update event is writing.
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * Picks the object a write event is writing: the new state on an update,
 * the object on a create.
 *
 * @spec exclude Listener plumbing, not a behavioural requirement.
 */
final class WrittenObject {

	/**
	 * The object being written: getNewObject() on an update, only a create
	 * has getObject().
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 *
	 * @spec exclude Listener plumbing, not a behavioural requirement.
	 */
	public function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()
}//end class
