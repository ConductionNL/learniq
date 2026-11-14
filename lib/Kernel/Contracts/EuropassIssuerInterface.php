<?php

/**
 * Contract of EuropassIssuer
 *
 * The public surface of EuropassIssuer that other components call, so they depend
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
 * Contract of EuropassIssuer.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EuropassIssuerInterface {

	/**
	 * The signed Europass payload of a credential, or null when it cannot be
	 * signed.
	 *
	 * @param array<string, mixed> $credential A signed credential.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function payloadFor(array $credential): ?array;

	/**
	 * The credential with a signed `edciPayload`, when its kind gets one and
	 * the tenant can sign; otherwise the credential unchanged.
	 *
	 * @param array<string, mixed> $credential A signed credential (id, issuerDid, tenant_id set).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-a-training-certificate-gets-its-europass-form
	 */
	public function withEuropass(array $credential): array;
}//end interface
