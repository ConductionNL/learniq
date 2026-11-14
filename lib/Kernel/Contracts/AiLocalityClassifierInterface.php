<?php

/**
 * Contract of AiLocalityClassifier
 *
 * The public surface of AiLocalityClassifier that other components call, so they depend
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
 * Contract of AiLocalityClassifier.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiLocalityClassifierInterface {

	/**
	 * Classify the locality of Hermiq's CURRENTLY ACTIVE chat provider.
	 *
	 * Resolves `hermiq.llm` cross-app (`IAppConfig::getValueString('hermiq',
	 * 'llm', ...)`), extracts `chatProvider` and — for the three
	 * broker-mediated providers — the matching `<provider>Config.credentialId`,
	 * then delegates to {@see classify()}. Degrades to `unverified` (never
	 * throws) when Hermiq is not installed, unconfigured, or the blob is
	 * malformed.
	 *
	 * @return array{locality: string, verified: bool, evidence: string}
	 *
	 * @spec openspec/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-derive-an-ai-feature-s-processing-locality-from-real-code-enforced-configuration-never-a-hand-typed-field
	 */
	public function classifyActiveProvider(): array;
}//end interface
