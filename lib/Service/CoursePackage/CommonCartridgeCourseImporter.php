<?php

/**
 * Learniq Common Cartridge Course Importer
 *
 * Extracts an IMS Common Cartridge 1.3 archive, walks its manifest
 * organization tree into Course/Lesson structure, routes every resource via
 * `CommonCartridgeResourceRouter`, and resolves the deferred QTI item import
 * through the shared `QtiImportService::importFromDirectory()` path.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing ZIP/XML from
 * an external interchange format cannot be expressed declaratively.
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

use OCA\Learniq\Service\CommonCartridgeParser;
use OCA\Learniq\Service\QtiImportService;

/**
 * Materialises a Common Cartridge package's organization tree + resources.
 */
class CommonCartridgeCourseImporter {
	/**
	 * Creates the Learniq objects the package materialises.
	 *
	 * @var CoursePackageObjectWriter
	 */
	private readonly CoursePackageObjectWriter $objectWriter;

	/**
	 * Builds the report entry rows.
	 *
	 * @var CoursePackageImportReporter
	 */
	private readonly CoursePackageImportReporter $reporter;

	/**
	 * Constructor.
	 *
	 * @param QtiImportService $qtiImportService QTI/CC assessment-item import (shared extraction + item parsing).
	 * @param CommonCartridgeParser $ccParser Common Cartridge manifest parser.
	 * @param CommonCartridgeResourceRouter $resourceRouter Per-resource routing to learniq targets.
	 * @param CoursePackageOutput $output The object writer, file writer and report entry builder.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly QtiImportService $qtiImportService,
		private readonly CommonCartridgeParser $ccParser,
		private readonly CommonCartridgeResourceRouter $resourceRouter,
		CoursePackageOutput $output,
	) {
		$this->objectWriter = $output->objectWriter;
		$this->reporter = $output->reporter;
	}//end __construct()

	/**
	 * Extract the archive into `$targetDir` and parse its `imsmanifest.xml`.
	 *
	 * @param string $packagePath Absolute path to the uploaded archive.
	 * @param string $targetDir Directory to extract into.
	 *
	 * @return array<string, mixed> `CommonCartridgeParser::parseManifest()` result.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	public function extractAndParse(string $packagePath, string $targetDir): array {
		$this->qtiImportService->extractZip(zipPath: $packagePath, targetDir: $targetDir);

		return $this->ccParser->parseManifest(dir: $targetDir);
	}//end extractAndParse()

	/**
	 * Materialise a parsed Common Cartridge manifest.
	 *
	 * @param array<string, mixed> $manifest `CommonCartridgeParser::parseManifest()` result.
	 * @param PackageContext $package The extracted package: its directory, the importing user (nc:files owner) and the tenant.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return string|null UUID of the top-level Course, or null if none was created.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	public function importManifest(array $manifest, PackageContext $package, array &$entries): ?string {
		$walk = new OrganizationWalk(
			resourcesById: $this->indexResources(resources: $manifest['resources']),
			package: $package,
		);
		$walk->entries = $entries;

		// Organization nodes -> Course (folders) / Lesson (leaf items referencing a resource).
		foreach ($manifest['organizationNodes'] as $node) {
			$this->importOrganizationNode(node: $node, walk: $walk);
		}

		$this->routeUnreferencedResources(resources: $manifest['resources'], walk: $walk);
		$entries = $walk->entries;

		$this->importQtiResources(dir: $package->dir, tenantId: $package->tenantId, entries: $entries);

		return $walk->topCourseId;
	}//end importManifest()

	/**
	 * Index a manifest's resource rows by their identifier.
	 *
	 * @param array<int, array<string, mixed>> $resources Manifest resource rows.
	 *
	 * @return array<string, array<string, mixed>> Resources keyed by identifier.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	private function indexResources(array $resources): array {
		$resourcesById = [];
		foreach ($resources as $resource) {
			$resourcesById[$resource['identifier']] = $resource;
		}

		return $resourcesById;
	}//end indexResources()

	/**
	 * Materialise one organization node: a folder becomes a child Course, a
	 * leaf item referencing a resource routes that resource into a Lesson slot.
	 *
	 * @param array<string, mixed> $node One organization node row.
	 * @param OrganizationWalk     $walk The walk's resources, package, Courses and entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	private function importOrganizationNode(array $node, OrganizationWalk $walk): void {
		$resource = null;
		if ($node['resourceIdentifier'] !== null) {
			$resource = ($walk->resourcesById[$node['resourceIdentifier']] ?? null);
		}

		if ($node['isFolder'] === true || $resource === null) {
			$this->importFolderNode(node: $node, walk: $walk);
			return;
		}

		// Leaf item referencing a resource -> Lesson, then route the underlying resource.
		$walk->entries[] = $this->resourceRouter->routeResource(
			slot: new ResourceSlot(
				resource: $resource,
				courseId: $this->resolveParentCourseId(node: $node, walk: $walk),
				lessonOrder: $node['order'],
				lessonTitle: $node['title'],
			),
			package: $walk->package,
		);
	}//end importOrganizationNode()

	/**
	 * Materialise a folder-level organization node as a child Course
	 * (module-as-a-course recursion).
	 *
	 * @param array<string, mixed> $node One organization node row.
	 * @param OrganizationWalk     $walk The walk's resources, package, Courses and entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	private function importFolderNode(array $node, OrganizationWalk $walk): void {
		$parentCourseId = null;
		if ($node['parentIdentifier'] !== null) {
			$parentCourseId = ($walk->courseIdByOrgId[$node['parentIdentifier']] ?? null);
		}

		$courseId = $this->objectWriter->createCourse(title: $node['title'], parentCourseId: $parentCourseId, tenantId: $walk->package->tenantId);
		// Cast the key: the map is declared array<string, string|null>, and a
		// mixed key from $node would widen it.
		$walk->courseIdByOrgId[(string)$node['identifier']] = $courseId;
		if ($walk->topCourseId === null) {
			$walk->topCourseId = $courseId;
		}

		$walk->entries[] = $this->reporter->entry(
			resourceIdentifier: $node['identifier'],
			resourceType: 'organization',
			title: $node['title'],
			result: ['outcome' => 'imported', 'targetType' => 'course', 'targetId' => $courseId, 'reason' => null]
		);
	}//end importFolderNode()

	/**
	 * Resolve the enclosing Course UUID for a leaf organization node,
	 * materialising an implicit top-level Course when the node has no
	 * enclosing folder.
	 *
	 * @param array<string, mixed> $node One organization node row.
	 * @param OrganizationWalk     $walk The walk's Courses; its top-level Course is set when implicit.
	 *
	 * @return string|null The enclosing Course UUID.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	private function resolveParentCourseId(array $node, OrganizationWalk $walk): ?string {
		$parentCourseId = $walk->topCourseId;
		if ($node['parentIdentifier'] !== null) {
			$parentCourseId = ($walk->courseIdByOrgId[$node['parentIdentifier']] ?? $walk->topCourseId);
		}

		if ($parentCourseId === null) {
			// A leaf item with no enclosing folder — materialise an implicit top-level Course first.
			$parentCourseId = $this->objectWriter->createCourse(title: 'Imported course', parentCourseId: null, tenantId: $walk->package->tenantId);
			$walk->topCourseId = $parentCourseId;
		}

		return $parentCourseId;
	}//end resolveParentCourseId()

	/**
	 * Resources not referenced by any organization item still get one entry
	 * each (e.g. a QTI item bank resource an assessment references directly,
	 * or an orphan resource with no manifest organization entry).
	 *
	 * @param array<int, array<string, mixed>> $resources Manifest resource rows.
	 * @param OrganizationWalk                 $walk      The walk's package, top-level Course and entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function routeUnreferencedResources(array $resources, OrganizationWalk $walk): void {
		$routedResourceIds = array_column($walk->entries, 'resourceIdentifier');
		foreach ($resources as $resource) {
			if (in_array($resource['identifier'], $routedResourceIds, strict: true) === true) {
				continue;
			}

			$walk->entries[] = $this->resourceRouter->routeResource(
				slot: new ResourceSlot(resource: $resource, courseId: $walk->topCourseId, lessonOrder: null, lessonTitle: null),
				package: $walk->package,
			);
		}
	}//end routeUnreferencedResources()

	/**
	 * Import every QTI/CC assessment-item resource in one bulk call via the shared
	 * `QtiImportService::importFromDirectory()` path, then resolve the earlier
	 * `pending-qti` placeholder entries against the created Item UUIDs.
	 *
	 * Positional pairing: both the CC parser and `QtiImportService::collectItemPaths()`
	 * walk the same manifest resource list in document order, so the Nth `pending-qti`
	 * entry corresponds to the Nth created Item UUID in the common case. When fewer
	 * Items were created than there are `pending-qti` entries, the tail of unmatched
	 * entries degrades rather than being reported as falsely `imported`.
	 *
	 * @param string $dir Extracted CC package directory.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator, mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	private function importQtiResources(string $dir, string $tenantId, array &$entries): void {
		$pendingIndexes = [];
		foreach ($entries as $idx => $entry) {
			if ($entry['outcome'] === 'pending-qti') {
				$pendingIndexes[] = $idx;
			}
		}

		if (empty($pendingIndexes) === true) {
			return;
		}

		$itemBankId = $this->objectWriter->createItemBank(name: 'Imported items', tenantId: $tenantId);
		$createdUuids = [];
		if ($itemBankId !== null) {
			$createdUuids = $this->qtiImportService->importFromDirectory(dir: $dir, itemBankId: $itemBankId, tenantId: $tenantId);
		}

		foreach ($pendingIndexes as $position => $idx) {
			$uuid = $createdUuids[$position] ?? null;
			if ($uuid !== null) {
				$entries[$idx]['outcome'] = 'imported';
				$entries[$idx]['targetType'] = 'item';
				$entries[$idx]['targetId'] = $uuid;
				$entries[$idx]['reason'] = null;
				continue;
			}

			$entries[$idx]['outcome'] = 'degraded';
			$entries[$idx]['targetType'] = null;
			$entries[$idx]['targetId'] = null;
			$entries[$idx]['reason'] = 'Item could not be parsed from the package (see application log).';
		}
	}//end importQtiResources()
}//end class
