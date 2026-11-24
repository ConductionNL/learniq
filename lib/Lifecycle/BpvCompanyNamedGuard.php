<?php

/**
 * Learniq BPV Company Named Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lifecycle guard for the BpvPlacement schema's `checkLeerbedrijf`
 * transition. A placement may be saved without a company while the student
 * is still looking for one (the `searching` phase on the placement board),
 * but it cannot go to the leerbedrijf check until it names the training
 * company and its KvK number: the check looks the company up by that number.
 *
 * ADR-031 legitimate exception: "Lifecycle guard — business rule that must
 * run before a state transition and cannot be expressed as a schema
 * declaration." Referenced from BpvPlacement's x-openregister-lifecycle
 * `checkLeerbedrijf` transition's `requires` in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/bpv/spec.md#requirement-a-coordinator-links-a-learner-to-a-training-company-in-a-placement
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Guards the BpvPlacement `checkLeerbedrijf` lifecycle transition.
 *
 * Refuses the transition while `trainingCompanyName` or
 * `trainingCompanyKvkNumber` is empty, and names the missing fields.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/bpv/spec.md#requirement-a-coordinator-links-a-learner-to-a-training-company-in-a-placement
 */
class BpvCompanyNamedGuard implements LifecycleGuardInterface {

	/**
	 * The fields the check needs, with the words the refusal uses for each.
	 *
	 * @var array<string,string>
	 */
	private const REQUIRED = [
		'trainingCompanyName'      => 'the training company',
		'trainingCompanyKvkNumber' => 'its KvK number',
	];

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string              $action The transition action being applied.
	 * @param string              $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny naming what the placement still lacks.
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/bpv/spec.md#requirement-a-coordinator-links-a-learner-to-a-training-company-in-a-placement
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$missing = self::missing(placement: $object);
		if ($missing === []) {
			return GuardResult::allow();
		}

		return GuardResult::deny(
			'The placement can only go to the leerbedrijf check once it names '.implode(' and ', $missing).'.'
		);

	}//end check()

	/**
	 * The words for each required field the placement leaves empty, in order.
	 *
	 * @param array<string,mixed> $placement The placement.
	 *
	 * @return list<string> Empty when the placement names its company and KvK number.
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/bpv/spec.md#requirement-a-coordinator-links-a-learner-to-a-training-company-in-a-placement
	 */
	public static function missing(array $placement): array {
		$missing = [];
		foreach (self::REQUIRED as $field => $words) {
			$value = $placement[$field] ?? null;
			if (is_string($value) === false || trim($value) === '') {
				$missing[] = $words;
			}
		}

		return $missing;

	}//end missing()
}//end class
