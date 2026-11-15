<?php

/**
 * Contract of OnboardingFolderSetting
 *
 * The public surface of OnboardingFolderSetting that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of OnboardingFolderSetting.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OnboardingFolderSettingInterface {

	/**
	 * Store a folder, given as a path in the teacher's own files. An empty
	 * path clears the setting. The whole of the teacher's files is refused:
	 * that would treat every Word file in the root as a lesson.
	 *
	 * @param string $userId The teacher.
	 * @param string $path Path relative to the teacher's files.
	 *
	 * @return array{folderId: int|null, path: string|null} The stored folder.
	 *
	 * @throws InvalidArgumentException When the path is not a folder in the teacher's files.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-file-is-not-a-folder
	 */
	public function choose(string $userId, string $path): array;

	/**
	 * The stored folder as the review page shows it: its id and its current
	 * path in the teacher's files, or nulls when none is set or it is gone.
	 *
	 * @param string $userId The teacher.
	 *
	 * @return array{folderId: int|null, path: string|null}
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
	 */
	public function describe(string $userId): array;

	/**
	 * The stored folder id, or 0 when the teacher chose none.
	 *
	 * @param string $userId The teacher.
	 *
	 * @return int The folder's file id, or 0.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
	 */
	public function folderId(string $userId): int;

	/**
	 * The tenant a teacher's objects belong to: their per-user binding, else
	 * the default tenant (CallerTenantResolver, which every learniq path uses).
	 *
	 * @param string $userId The teacher.
	 *
	 * @return string The tenant id.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
	 */
	public function tenantOf(string $userId): string;
}//end interface
