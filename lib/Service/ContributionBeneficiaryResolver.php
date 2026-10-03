<?php

/**
 * Learniq Contribution Beneficiary Resolver
 *
 * Reads the two things a shillinq school contribution says about learniq
 * (contract extracurricular-fee-to-shillinq v1): its `subject`, the chargeable
 * FeeItem, and its `beneficiary`, the learner the fee is for. Shared by the
 * settled listener and the grant guard, so both read the contract the same way.
 *
 * A beneficiary comes in the contract's two shapes: an object reference
 * (`{type: learner, register: learniq, schema: learner-profile, id: <profile uuid>}`,
 * which is how learniq raises it) or a bare Nextcloud user id
 * (`{type: learner, id: <uid>}`). Both resolve to the learner's Nextcloud user
 * id, which is what Entitlement.learnerId holds.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Resolves a contribution's chargeable and beneficiary to learniq's own ids.
 *
 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
 */
class ContributionBeneficiaryResolver {

	public const APP = 'learniq';
	private const REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The FeeItem id a PaymentRequest charges for, or null when its subject is not a learniq FeeItem.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 */
	public function feeItemIdOf(array $request): ?string {
		$subject = ($request['subject'] ?? null);
		if (is_array($subject) === false || ($subject['app'] ?? null) !== self::APP) {
			return null;
		}

		// The contract's example spells the schema `FeeItem`; learniq's slug is `fee-item`.
		$schema = strtolower((string)preg_replace('/[^A-Za-z]/', '', (string)($subject['schema'] ?? '')));
		$id = ($subject['id'] ?? null);
		if ($schema !== 'feeitem' || is_string($id) === false || $id === '') {
			return null;
		}

		return $id;
	}//end feeItemIdOf()

	/**
	 * The Nextcloud user id of a PaymentRequest's beneficiary, or null.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 */
	public function learnerIdOf(array $request): ?string {
		$beneficiary = ($request['beneficiary'] ?? null);
		if (is_array($beneficiary) === false) {
			return null;
		}

		$id = ($beneficiary['id'] ?? null);
		if (is_string($id) === false || $id === '') {
			return null;
		}

		if (isset($beneficiary['schema']) === false) {
			return $id;
		}

		$schema = strtolower((string)preg_replace('/[^A-Za-z]/', '', (string)$beneficiary['schema']));
		if (($beneficiary['register'] ?? self::REGISTER) !== self::REGISTER || $schema !== 'learnerprofile') {
			return null;
		}

		$profile = $this->objectService->find(
			id: $id,
			register: self::REGISTER,
			schema: self::PROFILE_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		$ncUserId = ($profile?->jsonSerialize()['ncUserId'] ?? null);
		if (is_string($ncUserId) === false || $ncUserId === '') {
			return null;
		}

		return $ncUserId;
	}//end learnerIdOf()
}//end class
