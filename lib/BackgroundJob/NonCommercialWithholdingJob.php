<?php

/**
 * Deferred withheld-mark sync of a course's non-commercial materials.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/open-learning-material
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\OpenMaterial\NonCommercialWithholding;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use Psr\Log\LoggerInterface;

/**
 * Syncs each course NonCommercialWithholdingCascade buffered once.
 *
 * @psalm-suppress UnusedClass Enqueued by ListenerDeferralService at request
 *  shutdown, never constructed by name.
 *
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 */
class NonCommercialWithholdingJob extends ForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ActorForwarding          $forwarding  Clock, session, user manager and tenant context.
	 * @param LoggerInterface          $logger      Logger; the base declares it protected.
	 * @param NonCommercialWithholding $withholding Marks or clears the course's NC materials.
	 */
	public function __construct(
		ActorForwarding $forwarding,
		LoggerInterface $logger,
		private readonly NonCommercialWithholding $withholding,
	) {
		parent::__construct(forwarding: $forwarding, logger: $logger);
	}//end __construct()

	/**
	 * Sync every buffered course once.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		$courses = [];
		foreach ($context->getEntries() as $entry) {
			$courseId = ($entry['courseId'] ?? null);
			if (is_string($courseId) === true && $courseId !== '') {
				$courses[$courseId] = true;
			}
		}

		foreach (array_keys($courses) as $courseId) {
			$this->withholding->syncCourse(courseId: (string)$courseId);
		}
	}//end runDeferred()
}//end class
