<?php

/**
 * Contract of CourseStoreDescriptor
 *
 * The public surface of CourseStoreDescriptor that other components call, so they depend
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

use OCA\OpenRegister\AppHost\Service\StoreDescriptor;

/**
 * Contract of CourseStoreDescriptor.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseStoreDescriptorInterface {

	public const KIND = 'course-package';

	/**
	 * The descriptor the store plane searches and resolves with.
	 *
	 * @return StoreDescriptor
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
	 */
	public function descriptor(): StoreDescriptor;

	/**
	 * Whether a slug names a shared course package
	 * (`course-package-<kebab>`, as CourseStoreRegistryObject::slug() builds it).
	 *
	 * @param string $slug The slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
	 */
	public function isCourseSlug(string $slug): bool;
}//end interface
