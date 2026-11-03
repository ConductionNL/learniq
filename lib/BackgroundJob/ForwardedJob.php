<?php

/**
 * Base of learniq's deferred jobs.
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
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use Psr\Log\LoggerInterface;

/**
 * An ActorForwardedJob that takes the actor forwarding collaborators as one
 * argument and hands them to OpenRegister's base.
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */
abstract class ForwardedJob extends ActorForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ActorForwarding $forwarding Clock, session, user manager and tenant context.
	 * @param LoggerInterface $logger     Logger; the base declares it protected.
	 */
	public function __construct(ActorForwarding $forwarding, LoggerInterface $logger) {
		parent::__construct(
			time: $forwarding->time,
			userSession: $forwarding->userSession,
			userManager: $forwarding->userManager,
			organisation: $forwarding->organisation,
			logger: $logger
		);
	}//end __construct()
}//end class
