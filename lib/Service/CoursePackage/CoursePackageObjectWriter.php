<?php

/**
 * Learniq Course Package Object Writer
 *
 * Owns every OpenRegister write a course-package import performs — Course,
 * Lesson, Material, LtiToolPlacement, ItemBank and the remaining
 * schema-generic rows — and resolves the created object's UUID. Extracted out
 * of `CoursePackageImportService` so the three format importers share one
 * persistence seam instead of each carrying an `ObjectService` dependency and
 * its own payload shapes.
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
 * @spec openspec/changes/course-package-import-export/design.md#data-model
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Creates the Learniq objects a course-package import materialises.
 */
class CoursePackageObjectWriter {

	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service for creating/persisting objects.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Create a `Course` object.
	 *
	 * @param string $title Course display name.
	 * @param string|null $parentCourseId Parent Course UUID for nested organization folders.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null Created Course UUID.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#data-model
	 */
	public function createCourse(string $title, ?string $parentCourseId, string $tenantId): ?string {
		return $this->create(
			schema: 'course',
			object: [
				'code' => 'IMPORT-' . substr(md5($title . microtime()), 0, 8),
				'name' => $title,
				'level' => 'other',
				'language' => 'en',
				'parentCourseId' => $parentCourseId,
				'lifecycle' => 'draft',
				'tenant_id' => $tenantId,
			]
		);
	}//end createCourse()

	/**
	 * Create a `Lesson` object.
	 *
	 * @param string|null $courseId Enclosing Course UUID.
	 * @param string $title Lesson title.
	 * @param int $order Manifest order.
	 * @param string $contentType Lesson content type.
	 * @param string $contentRef Material UUID (nc:files-resolved content lives on the Material).
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null Created Lesson UUID.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#data-model
	 */
	public function createLesson(
		?string $courseId,
		string $title,
		int $order,
		string $contentType,
		string $contentRef,
		string $tenantId,
	): ?string {
		return $this->create(
			schema: 'lesson',
			object: [
				'courseId' => $courseId,
				'name' => $title,
				'order' => $order,
				'contentType' => $contentType,
				'contentRef' => $contentRef,
				'lifecycle' => 'draft',
				'tenant_id' => $tenantId,
			]
		);
	}//end createLesson()

	/**
	 * Create a `Material` object.
	 *
	 * @param string $title Material title.
	 * @param string $kind One of Material's `kind` enum values.
	 * @param string|null $fileRef nc:files path, when `kind` carries file bytes.
	 * @param string|null $url External URL, when `kind: link`.
	 * @param string|null $courseId Enclosing Course UUID.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null Created Material UUID.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	public function createMaterial(string $title, string $kind, ?string $fileRef, ?string $url, ?string $courseId, string $tenantId): ?string {
		return $this->create(
			schema: 'material',
			object: [
				'title' => $title,
				'kind' => $kind,
				'fileRef' => $fileRef ?? '',
				'url' => $url,
				'courseId' => $courseId,
				'tenant_id' => $tenantId,
			]
		);
	}//end createMaterial()

	/**
	 * Create an `LtiToolPlacement` for an embedded `basiclti` resource.
	 *
	 * @param string|null $courseId Enclosing Course UUID.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null Created LtiToolPlacement UUID.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-an-lti-resource-becomes-a-placement-not-an-inline-link
	 */
	public function createLtiPlacement(?string $courseId, string $tenantId): ?string {
		return $this->create(
			schema: 'lti-tool-placement',
			object: [
				// Left blank: the package carries no live OpenConnector deployment binding.
				// An admin configures this before the placement can launch — reported as
				// `degraded`, never a silent success.
				'openconnectorDeploymentId' => '',
				'launchMode' => 'resource-link',
				'courseId' => $courseId,
				'lifecycle' => 'draft',
				'tenant_id' => $tenantId,
			]
		);
	}//end createLtiPlacement()

	/**
	 * Create an `ItemBank` to hold a package's imported assessment items.
	 *
	 * @param string $name Item bank display name.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null Created ItemBank UUID.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#data-model
	 */
	public function createItemBank(string $name, string $tenantId): ?string {
		return $this->create(
			schema: 'item-bank',
			object: ['name' => $name, 'tenant_id' => $tenantId, 'lifecycle' => 'draft']
		);
	}//end createItemBank()

	/**
	 * Create an arbitrary Learniq object in the `learniq` register and return its UUID.
	 *
	 * @param string $schema Target schema slug.
	 * @param array<string, mixed> $object Object payload.
	 *
	 * @return string|null Created object UUID.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#data-model
	 */
	public function create(string $schema, array $object): ?string {
		$saved = $this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: $schema,
			object: $object
		);

		return $this->extractUuid(saved: $saved);
	}//end create()

	/**
	 * Extract a created object's UUID from an `ObjectService::saveObject()` return value.
	 *
	 * @param mixed $saved Return value of `saveObject()` (array or an ObjectEntity-like object).
	 *
	 * @return string|null The UUID, or null if it could not be resolved.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#data-model
	 */
	private function extractUuid(mixed $saved): ?string {
		if (is_array($saved) === true) {
			$uuid = $saved['uuid'] ?? null;
			if (is_string($uuid) === true) {
				return $uuid;
			}

			return null;
		}

		// `is_callable()`, NOT `method_exists()`. ObjectEntity::getUuid() is
		// OCP\AppFramework\Db\Entity::__call magic, so method_exists() is FALSE
		// for it on a real entity — this branch never fired and extractUuid()
		// returned null for every save, leaving createCourse() with no course
		// id and skipping the whole QTI item-bank import.
		if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {
			$uuid = $saved->getUuid();
			if (is_string($uuid) === true) {
				return $uuid;
			}

			return null;
		}

		return null;
	}//end extractUuid()
}//end class
