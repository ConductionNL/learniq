<?php

/**
 * Learniq sign-up write
 *
 * One creating or updating event of an elective sign-up, as
 * ElectiveSignUpRules answers it: the object it writes, and the event
 * refused or the new sign-up stamped.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * A sign-up write event and how the rules settle it.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */
final class SignUpWrite {

	/**
	 * Constructor.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectCreatingEvent|ObjectUpdatingEvent $event,
	) {
	}//end __construct()

	/**
	 * The object the event writes: the new object of an update.
	 *
	 * @return object
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function object(): object {
		if ($this->event instanceof ObjectUpdatingEvent) {
			return $this->event->getNewObject();
		}

		return $this->event->getObject();
	}//end object()

	/**
	 * Refuse the write with its message, so no other listener runs; or,
	 * when nothing refuses it, stamp a new sign-up with who made it and how.
	 *
	 * @param string|null                                                              $refusal Why the sign-up may not be written, or null.
	 * @param array{uid: string, admin: bool, staff: bool, integration: bool, via: string} $caller  The writer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function settle(?string $refusal, array $caller): void {
		if ($refusal !== null) {
			$this->event->setErrors(['message' => $refusal]);
			$this->event->stopPropagation();
			return;
		}

		if ($this->event instanceof ObjectCreatingEvent) {
			$this->event->setModifiedData(['madeBy' => $caller['uid'], 'madeVia' => $caller['via']]);
		}
	}//end settle()
}//end class
