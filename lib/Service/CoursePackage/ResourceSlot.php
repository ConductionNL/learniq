<?php

/**
 * Learniq course package resource slot.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
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
 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

/**
 * One package resource and the place it lands: its enclosing Course and,
 * when it has an owning Lesson slot, that Lesson's order and title.
 *
 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
 */
final class ResourceSlot {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $resource    One `CommonCartridgeParser` resource row.
	 * @param string|null          $courseId    Enclosing Course UUID, when known.
	 * @param int|null             $lessonOrder Manifest order, when the resource has an owning Lesson slot.
	 * @param string|null          $lessonTitle Lesson title, when the resource has an owning Lesson slot.
	 */
	public function __construct(
		public readonly array $resource,
		public readonly ?string $courseId,
		public readonly ?int $lessonOrder,
		public readonly ?string $lessonTitle,
	) {
	}//end __construct()
}//end class
