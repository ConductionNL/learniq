<?php

/**
 * Learniq portal pupil step.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use Closure;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IUser;

/**
 * What a pupil-facing receiver does for the pupil, and how it words a
 * refusal to them.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
final class PortalLearnerStep {

	/**
	 * Constructor.
	 *
	 * @param Closure(PortalLearner):PortalOutcome $run      The step for the resolved pupil.
	 * @param Closure(string,?IUser):string        $messages The receiver's pupil-facing message for a reason.
	 */
	public function __construct(
		private readonly Closure $run,
		private readonly Closure $messages,
	) {
	}//end __construct()

	/**
	 * Run the step for the pupil.
	 *
	 * @param PortalLearner $learner The resolved pupil.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function run(PortalLearner $learner): PortalOutcome {
		return ($this->run)($learner);
	}//end run()

	/**
	 * The pupil-facing message for a reason.
	 *
	 * @param string     $reason The refusal reason.
	 * @param IUser|null $user   The pupil, null when there is no account.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function message(string $reason, ?IUser $user): string {
		return ($this->messages)($reason, $user);
	}//end message()
}//end class
