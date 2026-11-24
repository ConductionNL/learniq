<?php

/**
 * Learniq Leerbedrijf Verification Provider Interface
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pluggable interface for the SBB erkend-leerbedrijf check (WEB art.
 * 7.2.8/7.2.9 — the employer hosting a BPV placement must be a leerbedrijf
 * erkend by SBB, Samenwerkingsorganisatie Beroepsonderwijs Bedrijfsleven).
 * Implementations are named by BpvPlacement's
 * `trainingCompanyVerification.provider` field and resolved by the application
 * container. Learniq ships exactly one, IntegriqLeerbedrijfVerification, which
 * delegates the lookup to integriq's SBB source and is used when no provider
 * is named; without integriq it answers `pending`, so the placement cannot
 * confirm but nothing breaks.
 *
 * Analogous to ProvidesProctoring (assessment) and ProvidesPlagiarismCheck
 * (assignments) — this is the third pluggable-provider seam in Learniq.
 *
 * This is the single PHP seam for leerbedrijf verification per the `bpv`
 * spec (ADR-031: "External-system contract — SDK/API bridge that must be
 * expressed in PHP").
 *
 * @category Bpv
 * @package  OCA\Learniq\Bpv
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */

declare(strict_types=1);

namespace OCA\Learniq\Bpv;

/**
 * Pluggable leerbedrijf-verification provider interface.
 *
 * Adapters for external leerbedrijf registers (the SBB erkend-leerbedrijf
 * register, or any custom on-premises/test double) implement this interface.
 * Learniq ships IntegriqLeerbedrijfVerification; another adapter may be named in its place.
 *
 * Implementation notes:
 * - `verify()` is a synchronous, per-placement, blocking check — NOT a bulk
 *   job. It is invoked once per `checkLeerbedrijf` transition by
 *   BpvLeerbedrijfVerificationHandler, which writes the result back onto
 *   BpvPlacement.leerbedrijfVerification. BpvConfirmationGuard reads that
 *   STORED result (never calls the provider synchronously during a
 *   transition), the same pattern AssessmentPublishGuard and
 *   LearningPlanSignatureGuard use for other stored lifecycle-adjacent state.
 * - `status` in the returned array MUST be one of `verified`, `rejected`,
 *   `expired` or `pending`. Only `verified` satisfies BpvConfirmationGuard.
 *   An optional `reason` says why a result is not `verified`.
 */
interface ProvidesLeerbedrijfVerification {
	/**
	 * Verify whether a company is an SBB-erkend leerbedrijf.
	 *
	 * @param string $kvkOrErkenningNumber The company's KVK number or an existing SBB
	 *                                     erkenning number to re-check.
	 *
	 * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array, reason?: ?string}
	 *                                                                                         `status`: `verified`|`rejected`|`expired`|`pending`.
	 *                                                                                         `erkenningNumber` and `expiresAt` are populated when
	 *                                                                                         `status` is `verified`. `raw` carries the provider's
	 *                                                                                         raw response payload for audit purposes.
	 *
	 * @throws \RuntimeException When the provider cannot be reached.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
	 */
	public function verify(string $kvkOrErkenningNumber): array;
}//end interface
