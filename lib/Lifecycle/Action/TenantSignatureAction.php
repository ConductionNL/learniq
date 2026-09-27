<?php

/**
 * Learniq TenantSignatureAction
 *
 * Transition action that HMAC-signs the object being saved with OpenRegister's
 * current tenant key. Declared on Attestation.sign, BsaWarning.issue and
 * BsaDecision.decide.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\TenantKeyService;
use RuntimeException;

/**
 * Writes `signature` and `signingKeyId` onto a transitioning object.
 *
 * The signing used to live in the transition's guard, which wrote into a
 * mutable payload. OpenRegister calls guards by value and its guard interface
 * forbids mutation, so the guard now only checks and this action does the
 * write: OpenRegister's LifecycleActionListener merges the returned array into
 * the object it saves (learniq#983).
 *
 * The digest is HMAC-SHA256 over the object's JSON with keys sorted at every
 * level and `signature`, `signingKeyId` and `lifecycle` left out, so a stale
 * signature or the state change never alters it. `signingKeyId` is the first
 * 16 hex characters of the key's SHA-256, a fingerprint of the key in use.
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-maintain-an-append-only-signed-evidence-log
 */
class TenantSignatureAction implements LifecycleActionInterface {

	/**
	 * Fields left out of the signed payload.
	 *
	 * @var string[]
	 */
	private const EXCLUDED = ['signature', 'signingKeyId', 'lifecycle'];

	/**
	 * Constructor.
	 *
	 * @param TenantKeyService $tenantKeyService OR tenant-key abstraction that exposes the current HMAC signing key.
	 */
	public function __construct(
		private readonly TenantKeyService $tenantKeyService,
	) {
	}//end __construct()

	/**
	 * Sign the object and return it with the signature fields set.
	 *
	 * @param array<string,mixed> $objectData   The object after the lifecycle field moved to its target.
	 * @param array<string,mixed> $previousData The object before the transition (unused).
	 * @param array<string,mixed> $parameters   The declared `actionParameters` (unused).
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The object with `signature` and `signingKeyId` set.
	 *
	 * @throws RuntimeException When the tenant has no signing key: an unsigned record must not be saved.
	 *
	 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$tenantId = (string)($objectData['tenant_id'] ?? '');
		$tenantKey = $this->tenantKeyService->getCurrentTenantKey($tenantId);

		if ($tenantKey === '') {
			throw new RuntimeException(
				sprintf('Lifecycle action "%s" found no signing key for tenant "%s", so the record can not be signed.', $actionName, $tenantId)
			);
		}

		$payload = $this->deepKsort(data: array_diff_key($objectData, array_flip(self::EXCLUDED)));
		$canonical = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$objectData['signature'] = hash_hmac('sha256', $canonical, $tenantKey);
		$objectData['signingKeyId'] = substr(hash('sha256', $tenantKey), 0, 16);

		return $objectData;
	}//end execute()

	/**
	 * Recursively sort an array by keys at all nesting levels.
	 *
	 * @param array<array-key,mixed> $data The array to sort.
	 *
	 * @return array<array-key,mixed> The sorted array.
	 */
	private function deepKsort(array $data): array {
		foreach ($data as $key => $value) {
			if (is_array($value) === true) {
				$data[$key] = $this->deepKsort(data: $value);
			}
		}

		ksort($data);
		return $data;
	}//end deepKsort()
}//end class
