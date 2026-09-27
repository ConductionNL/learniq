<?php

/**
 * Learniq Pupil Voice Guard
 *
 * Lifecycle guard for the DeliberationRecord schema's `scheduled → recorded`
 * transition (the `record` action). Enforces the 2025 Wet versterking positie
 * ouders en leerlingen in passend onderwijs hoorrecht (insight 1145): a
 * deliberation MUST NOT be finalised unless the pupil's own voice was either
 * heard directly (`pupilVoice.heard === true`) or explicitly, non-silently
 * waived with a reason (`pupilVoice.waived === true` and a non-empty
 * `pupilVoice.waiverReason`).
 *
 * Mirrors LearningPlanSignatureGuard's structure: a single-responsibility
 * guard blocking one lifecycle transition on a declared pre-condition.
 *
 * Referenced from DeliberationRecord.x-openregister-lifecycle.transitions.record.requires.
 * OR resolves guards by fully-qualified class name from the schema — no
 * Application.php registration needed.
 *
 * ADR-031 legitimate exception: a hard, non-bypassable field-presence gate on
 * a legally-mandated field cannot be expressed as schema metadata alone (the
 * schema's own `required` array cannot express "heard OR (waived AND
 * waiverReason)" as a conditional pre-condition on a *transition*, only on
 * object shape at any time).
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
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-3.3
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-the-pupils-own-voice-hoorrecht-is-a-first-class-non-optional-field
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the DeliberationRecord `scheduled → recorded` lifecycle transition.
 *
 * Blocks the transition unless pupilVoice.heard is true, or pupilVoice.waived
 * is true with a non-empty waiverReason.
 */
class PupilVoiceGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'The pupil must have been heard, or the hearing waived with a reason, before this record is final.';
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-3.3
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(record: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * Allow the `scheduled → recorded` transition.
	 *
	 * @param array<string,mixed> $record The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when pupilVoice.heard is true, or pupilVoice.waived is true
	 *              with a non-empty waiverReason; false otherwise.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-3.3
	 */
	private function allows(array $record): bool {
		$recordId = $record['id'] ?? ($record['uuid'] ?? '?');
		$pupilVoice = $record['pupilVoice'] ?? [];

		if (is_array($pupilVoice) === false) {
			$this->logger->warning(
				'[PupilVoiceGuard] DeliberationRecord {id}: pupilVoice is not an object — blocking record.',
				['id' => $recordId]
			);
			return false;
		}

		$heard = $pupilVoice['heard'] ?? false;
		$waived = $pupilVoice['waived'] ?? false;
		$waiverReason = $pupilVoice['waiverReason'] ?? null;

		if ($heard === true) {
			return true;
		}

		if ($waived === true && is_string($waiverReason) === true && trim($waiverReason) !== '') {
			return true;
		}

		$reasonSet = (is_string($waiverReason) === true && trim($waiverReason) !== '');

		$waivedLabel = 'false';
		if ($waived === true) {
			$waivedLabel = 'true';
		}

		$reasonSetLabel = 'false';
		if ($reasonSet === true) {
			$reasonSetLabel = 'true';
		}

		// Heard is known false here (the heard===true branch above already returned).
		$this->logger->info(
			'[PupilVoiceGuard] DeliberationRecord {id}: hoorrecht not satisfied '
			. '(heard=false, waived={waived}, waiverReason set={reasonSet}) — blocking record transition.',
			[
				'id' => $recordId,
				'waived' => $waivedLabel,
				'reasonSet' => $reasonSetLabel,
			]
		);

		return false;
	}//end allows()
}//end class
