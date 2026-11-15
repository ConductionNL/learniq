<?php

/**
 * Learniq StampTransitionTimeAction
 *
 * Transition action that writes the server's time into a declared field, for
 * a transition that records when it happened but not who did it, such as
 * Attestation.sign stamping `signedAt`. The field is readOnly on the schema,
 * so it is written on the transition path and never taken from the caller.
 *
 * Declared before any signing action on the same transition, so the stamp is
 * part of what is signed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use RuntimeException;

/**
 * Stamps when a transition happened, never trusting a caller-supplied value.
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
 */
class StampTransitionTimeAction implements LifecycleActionInterface {

	/**
	 * Stamp the current UTC time onto the declared field.
	 *
	 * @param LifecycleActionContext $context The transition; its parameters are the declared
	 *                                        `actionParameters`: `timeField`.
	 *
	 * @return array<string,mixed> The object with the stamp applied.
	 *
	 * @throws RuntimeException When no timeField is declared.
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;
		$timeField  = ($context->parameters['timeField'] ?? null);
		if (is_string($timeField) === false || $timeField === '') {
			throw new RuntimeException(
				sprintf('Lifecycle action "%s" declares no timeField to stamp.', $context->actionName)
			);
		}

		$objectData[$timeField] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		return $objectData;
	}//end execute()
}//end class
