<?php

/**
 * Contract of TimetableExchangeSettings
 *
 * The public surface of TimetableExchangeSettings that other components call, so they depend
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
 * Contract of TimetableExchangeSettings.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimetableExchangeSettingsInterface {

	/**
	 * The rostering systems integriq delivers from: its Source row ids, as
	 * PlanninqTimetableImport posts them (`roster-zermelo`, ...).
	 *
	 * @var array<int, string>
	 */
	public const ROSTER_SOURCES = ['roster-zermelo', 'roster-untis-oneroster', 'roster-xedule', 'roster-timeedit'];

	/**
	 * One source's map.
	 *
	 * @param string $source The rostering system.
	 *
	 * @return array<string, string> Group code => cohort id.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-import-without-a-posted-map-uses-the-kept-map
	 */
	public function groupMapFor(string $source): array;

	/**
	 * Every source's map, one entry per known source, empty when none is kept.
	 *
	 * @return array<string, array<string, string>> Source => group code => cohort id.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function groupMaps(): array;

	/**
	 * Store the maps and the receiver. Call validate() first.
	 *
	 * @param array<string, mixed> $groupMaps  Source => group code => cohort id.
	 * @param string               $receiverId The receiver id, '' to clear it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function save(array $groupMaps, string $receiverId): void;

	/**
	 * The integriq receiver of the school's SWV hand-offs, '' when none is set.
	 *
	 * @return string The receiver id.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function swvReceiverId(): string;

	/**
	 * Why the posted settings cannot be saved, or null when they can.
	 *
	 * @param mixed  $groupMaps  The posted maps.
	 * @param string $receiverId The posted receiver id.
	 *
	 * @return string|null The reason, in English (the controller translates it).
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function validate(mixed $groupMaps, string $receiverId): ?string;
}//end interface
