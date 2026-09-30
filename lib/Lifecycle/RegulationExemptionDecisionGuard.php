<?php

/**
 * Learniq Regulation Exemption Decision Guard
 *
 * Lifecycle guard for the RegulationExemption `grant` and `reject`
 * transitions. Both need the rationale and policy reference that
 * ExemptionDecisionGuard asks of an exam board exemption; this guard calls
 * that guard rather than copying its rule (design D3). A grant also needs a
 * validity end date, and may not be given by the person who requested the
 * exemption.
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
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use DateTimeImmutable;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Throwable;

/**
 * Guards the decision on a regulation exemption.
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */
class RegulationExemptionDecisionGuard implements LifecycleGuardInterface {

	/**
	 * Keys the caller sends with the transition that this guard reads; each is
	 * a declared `inputs` entry on the transition.
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = ['decisionRationale', 'policyReference', 'validFrom', 'validUntil'];

	/**
	 * Constructor.
	 *
	 * @param ExemptionDecisionGuard $rationale The rationale and policy reference rule.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExemptionDecisionGuard $rationale,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the decision (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The exemption at its target state, transition inputs merged in.
	 * @param string              $action The transition action, `grant` or `reject`.
	 * @param string              $userId The uid of the caller, '' without a session.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-grant-without-a-rationale-is-refused
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-a-requester-cannot-grant-their-own-request
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($userId === '') {
			return GuardResult::deny('Sign in to decide on an exemption.');
		}

		$rationale = $this->rationale->check(object: $object, action: $action, userId: $userId);
		if ($rationale->isAllowed() === false) {
			return $rationale;
		}

		if ($action !== 'grant') {
			return GuardResult::allow();
		}

		if ((string)($object['requestedBy'] ?? '') === $userId) {
			return GuardResult::deny('The person who requested an exemption can not also grant it.');
		}

		return $this->checkPeriod(object: $object);
	}//end check()

	/**
	 * A grant needs an end date, on or after the start date when there is one.
	 *
	 * @param array<string,mixed> $object The exemption with the transition inputs merged in.
	 *
	 * @return GuardResult
	 */
	private function checkPeriod(array $object): GuardResult {
		$until = self::date(value: ($object['validUntil'] ?? null));
		if ($until === null) {
			return GuardResult::deny('A granted exemption needs a valid until date.');
		}

		$from = self::date(value: ($object['validFrom'] ?? null));
		if ($from !== null && $until < $from) {
			return GuardResult::deny('The valid until date is before the valid from date.');
		}

		return GuardResult::allow();
	}//end checkPeriod()

	/**
	 * A date string as a date, or null when it is empty or not a date.
	 *
	 * @param mixed $value The field value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end date()
}//end class
