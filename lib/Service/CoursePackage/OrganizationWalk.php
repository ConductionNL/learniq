<?php

/**
 * Learniq Common Cartridge organization walk.
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
 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

/**
 * The state of one walk over a Common Cartridge manifest's organization: the
 * resources by identifier, the package, the Courses created so far and the
 * report entries.
 *
 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
 */
final class OrganizationWalk {

	/**
	 * Organization identifier to created Course UUID.
	 *
	 * @var array<string, string|null>
	 */
	public array $courseIdByOrgId = [];

	/**
	 * The top-level Course UUID, once one exists.
	 *
	 * @var string|null
	 */
	public ?string $topCourseId = null;

	/**
	 * Report entries, one per organization node and resource.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $entries = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $resourcesById Resources keyed by identifier.
	 * @param PackageContext                      $package       The extracted package.
	 */
	public function __construct(
		public readonly array $resourcesById,
		public readonly PackageContext $package,
	) {
	}//end __construct()
}//end class
