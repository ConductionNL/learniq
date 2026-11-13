<?php

/**
 * Contract of RegulationAssignPreview
 *
 * The public surface of RegulationAssignPreview that other components call, so they depend
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
 * Contract of RegulationAssignPreview.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegulationAssignPreviewInterface {

	/**
	 * The preview of assigning the regulation, optionally to one audience.
	 *
	 * @param array<string,mixed>                     $regulation The published Regulation object.
	 * @param array{kind: string, value: string}|null $audience   The chosen audience, or null for the audience lists only.
	 *
	 * @return array<string,mixed> { courseName, audiences } plus { total, alreadyEnrolled, toEnrol, rows } once an audience is chosen.
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
	 */
	public function preview(array $regulation, ?array $audience=null): array;
}//end interface
