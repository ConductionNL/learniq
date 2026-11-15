<?php

/**
 * Contract of CourseShareExportService
 *
 * The public surface of CourseShareExportService that other components call, so they depend
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

use OCA\Learniq\Exception\SharingBlockedException;
use RuntimeException;

/**
 * Contract of CourseShareExportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseShareExportServiceInterface {

	public const PURPOSE_DOWNLOAD = 'download';

	public const PURPOSE_STORE = 'store';

	/**
	 * Build the share package for a course, or refuse with every reason.
	 *
	 * @param string $courseId      UUID of the course.
	 * @param string $userId        Nextcloud user id of whoever confirms.
	 * @param bool   $noPupilData   The user confirmed the package holds no pupil data.
	 * @param bool   $rightsCleared The user confirmed the school may share everything in it.
	 * @param string $purpose       `download` or `store`.
	 *
	 * @return array<string, mixed> The share package.
	 *
	 * @throws SharingBlockedException When the gate refuses.
	 * @throws RuntimeException        When the course is missing or the consent cannot be recorded.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-every-share-export-leaves-a-consent-record
	 */
	public function buildPackage(
		string $courseId,
		string $userId,
		bool $noPupilData,
		bool $rightsCleared,
		string $purpose=self::PURPOSE_DOWNLOAD
	): array;

	/**
	 * The share package as a JSON download.
	 *
	 * @param string $courseId      UUID of the course.
	 * @param string $userId        Nextcloud user id of whoever confirms.
	 * @param bool   $noPupilData   The user confirmed the package holds no pupil data.
	 * @param bool   $rightsCleared The user confirmed the school may share everything in it.
	 *
	 * @return array{content: string, filename: string, contentType: string}
	 *
	 * @throws SharingBlockedException When the gate refuses.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
	 */
	public function export(string $courseId, string $userId, bool $noPupilData, bool $rightsCleared): array;
}//end interface
