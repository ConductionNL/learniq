<?php

/**
 * Learniq guardian-or-self submit refusal log.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Writes the one log line a refused guardian-or-self submit gets, prefixed
 * with the guard that refused it. Built by that guard.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */
final class GuardianSubmitLog {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 * @param string          $guard  Short name of the refusing guard, as the log prefix shows it.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $guard,
	) {
	}//end __construct()

	/**
	 * Log why the transition is refused; an allowed verdict logs nothing.
	 *
	 * @param GuardianSubmitVerdict $verdict The check's verdict.
	 * @param string                $subject The guarded schema's name, as the missing-learner line shows it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
	 */
	public function refusal(GuardianSubmitVerdict $verdict, string $subject): void {
		$prefix = '[' . $this->guard . '] ';
		match ($verdict->outcome) {
			GuardianSubmitVerdict::NO_LEARNER => $this->logger->warning(
				$prefix . $subject . ' has no learnerId; blocking submit.'
			),
			GuardianSubmitVerdict::NO_CALLER => $this->logger->info(
				$prefix . 'No authenticated user in session; blocking submit.'
			),
			GuardianSubmitVerdict::NO_PROFILE => $this->logger->warning(
				$prefix . 'No LearnerProfile found for learnerId {learnerId}; blocking submit (fail closed).',
				$verdict->context
			),
			GuardianSubmitVerdict::NOT_GUARDIAN => $this->logger->info(
				$prefix . 'Caller {caller} is not a linked guardian of learner '
				. '{learnerId} and is not the learner; blocking submit.',
				$verdict->context
			),
			default => null,
		};
	}//end refusal()
}//end class
