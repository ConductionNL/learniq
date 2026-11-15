<?php

/**
 * Contract of OpeningHoursSettings
 *
 * The public surface of OpeningHoursSettings that other components call, so they depend
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
 * Contract of OpeningHoursSettings.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OpeningHoursSettingsInterface {

	/**
	 * The stored opening hours, or the default.
	 *
	 * @return array{weekdays:array<string,array{opens:string,closes:string}|null>,closedOnStudyDays:bool}
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function get(): array;

	/**
	 * Store the opening hours.
	 *
	 * @param array<string,mixed> $value Valid opening hours (see validate()).
	 *
	 * @return array{weekdays:array<string,array{opens:string,closes:string}|null>,closedOnStudyDays:bool} What was stored.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function save(array $value): array;

	/**
	 * Why a value cannot be stored, or null when it can.
	 *
	 * @param mixed $value The submitted opening hours.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function validate(mixed $value): ?string;
}//end interface
