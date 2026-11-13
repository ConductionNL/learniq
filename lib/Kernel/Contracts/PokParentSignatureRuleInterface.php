<?php

/**
 * Contract of PokParentSignatureRule
 *
 * The public surface of PokParentSignatureRule that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of PokParentSignatureRule.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PokParentSignatureRuleInterface {

	/**
	 * The student's age cannot be established.
	 */
	public const REASON_UNKNOWN_AGE = 'unknown-age';

	/**
	 * Whether a parent signs this POK.
	 *
	 * @param array<string, mixed> $pok The Praktijkovereenkomst.
	 * @param string|null $studentSignedAt The student's PokSignature.signedAt, or null when they have not signed.
	 *
	 * @return array{required: bool, reason: string, parentIds: array<int, string>}
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function evaluate(array $pok, ?string $studentSignedAt): array;

	/**
	 * The student's earliest signature time among a version's PokSignatures,
	 * or null. The earliest gives the youngest age, so a doubt falls on the
	 * side of the parent signing.
	 *
	 * @param array<int, array<string, mixed>> $signatures PokSignature rows.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function studentSignedAt(array $signatures): ?string;
}//end interface
