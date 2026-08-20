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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
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
	 * Constructor.
	 *
	 * @param QtiImportService $qtiImportService QTI/CC assessment-item import (shared extraction + item parsing).
	 * @param CommonCartridgeParser $ccParser Common Cartridge manifest parser.
	 * @param CommonCartridgeResourceRouter $resourceRouter Per-resource routing to learniq targets.
	 * @param CoursePackageObjectWriter $objectWriter Creates the learniq objects the package materialises.
	 * @param CoursePackageImportReporter $reporter Builds the report entry rows.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly QtiImportService $qtiImportService,
		private readonly CommonCartridgeParser $ccParser,
		private readonly CommonCartridgeResourceRouter $resourceRouter,
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageImportReporter $reporter,
	) {
	}//end __construct()

	/**
	 * Extract the archive into `$targetDir` and parse its `imsmanifest.xml`.
	 *
	 * @param string $packagePath Absolute path to the uploaded archive.
	 * @param string $targetDir Directory to extract into.
	 *
	 * @return array<string, mixed> `CommonCartridgeParser::parseManifest()` result.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	public function extractAndParse(string $packagePath, string $targetDir): array {
		$this->qtiImportService->extractZip(zipPath: $packagePath, targetDir: $targetDir);

		return $this->ccParser->parseManifest(dir: $targetDir);
	}//end extractAndParse()

	/**
	 * Materialise a parsed Common Cartridge manifest.
	 *
	 * @param string $dir Extracted CC package directory.
	 * @param array<string, mixed> $manifest `CommonCartridgeParser::parseManifest()` result.
	 * @param string $importedBy NC user id (used as the nc:files owner for resolved Material bytes).
	 * @param string $tenantId Tenant UUID stamped on every created object.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return string|null UUID of the top-level Course, or null if none was created.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	public function importManifest(string $dir, array $manifest, string $importedBy, string $tenantId, array &$entries): ?string {
		$resourcesById = $this->indexResources(resources: $manifest['resources']);

		// Organization nodes -> Course (folders) / Lesson (leaf items referencing a resource).
		$courseIdByOrgId = [];
		$topCourseId = null;
		foreach ($manifest['organizationNodes'] as $node) {
			$this->importOrganizationNode(
				node: $node,
				resourcesById: $resourcesById,
				dir: $dir,
				importedBy: $importedBy,
				tenantId: $tenantId,
				courseIdByOrgId: $courseIdByOrgId,
				topCourseId: $topCourseId,
				entries: $entries,
			);
		}

		$this->routeUnreferencedResources(
			resources: $manifest['resources'],
			dir: $dir,
			topCourseId: $topCourseId,
			importedBy: $importedBy,
			tenantId: $tenantId,
			entries: $entries,
		);

		$this->importQtiResources(dir: $dir, tenantId: $tenantId, entries: $entries);

		return $topCourseId;
	}//end importManifest()

	/**
	 * Index a manifest's resource rows by their identifier.
	 *
	 * @param array<int, array<string, mixed>> $resources Manifest resource rows.
	 *
	 * @return array<string, array<string, mixed>> Resources keyed by identifier.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
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
	 * @param array<string, array<string, mixed>> $resourcesById Resources keyed by identifier.
	 * @param string $dir Extracted CC package directory.
	 * @param string $importedBy NC user id.
	 * @param string $tenantId Tenant UUID.
	 * @param array<string, string|null> $courseIdByOrgId Organization-identifier -> created Course UUID (by reference).
	 * @param string|null $topCourseId Top-level Course UUID (by reference).
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	private function importOrganizationNode(
		array $node,
		array $resourcesById,
		string $dir,
		string $importedBy,
		string $tenantId,
		array &$courseIdByOrgId,
		?string &$topCourseId,
		array &$entries,
	): void {
		$resource = null;
		if ($node['resourceIdentifier'] !== null) {
			$resource = ($resourcesById[$node['resourceIdentifier']] ?? null);
		}

		if ($node['isFolder'] === true || $resource === null) {
			$this->importFolderNode(
				node: $node,
				tenantId: $tenantId,
				courseIdByOrgId: $courseIdByOrgId,
				topCourseId: $topCourseId,
				entries: $entries,
			);
			return;
		}

		// Leaf item referencing a resource -> Lesson, then route the underlying resource.
		$parentCourseId = $this->resolveParentCourseId(
			node: $node,
			courseIdByOrgId: $courseIdByOrgId,
			topCourseId: $topCourseId,
			tenantId: $tenantId,
		);

		$this->resourceRouter->routeResource(
			resource: $resource,
			dir: $dir,
			courseId: $parentCourseId,
			lessonOrder: $node['order'],
			lessonTitle: $node['title'],
			importedBy: $importedBy,
			tenantId: $tenantId,
			entries: $entries,
		);
	}//end importOrganizationNode()

	/**
	 * Materialise a folder-level organization node as a child Course
	 * (module-as-a-course recursion).
	 *
	 * @param array<string, mixed> $node One organization node row.
	 * @param string $tenantId Tenant UUID.
	 * @param array<string, string|null> $courseIdByOrgId Organization-identifier -> created Course UUID (by reference).
	 * @param string|null $topCourseId Top-level Course UUID (by reference).
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	private function importFolderNode(
		array $node,
		string $tenantId,
		array &$courseIdByOrgId,
		?string &$topCourseId,
		array &$entries,
	): void {
		$parentCourseId = null;
		if ($node['parentIdentifier'] !== null) {
			$parentCourseId = ($courseIdByOrgId[$node['parentIdentifier']] ?? null);
		}

		$courseId = $this->objectWriter->createCourse(title: $node['title'], parentCourseId: $parentCourseId, tenantId: $tenantId);
		// Cast the key: this map is declared array<string, string|null> and
		// passed BY REFERENCE, so a mixed key from $node degrades it to
		// array<string|null> at the call boundary.
		$courseIdByOrgId[(string)$node['identifier']] = $courseId;
		if ($topCourseId === null) {
			$topCourseId = $courseId;
		}

		$entries[] = $this->reporter->entry(
			resourceIdentifier: $node['identifier'],
			resourceType: 'organization',
			title: $node['title'],
			outcome: 'imported',
			targetType: 'course',
			targetId: $courseId,
			reason: null,
		);
	}//end importFolderNode()

	/**
	 * Resolve the enclosing Course UUID for a leaf organization node,
	 * materialising an implicit top-level Course when the node has no
	 * enclosing folder.
	 *
	 * @param array<string, mixed> $node One organization node row.
	 * @param array<string, string|null> $courseIdByOrgId Organization-identifier -> created Course UUID.
	 * @param string|null $topCourseId Top-level Course UUID (by reference).
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null The enclosing Course UUID.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
	 */
	private function resolveParentCourseId(array $node, array $courseIdByOrgId, ?string &$topCourseId, string $tenantId): ?string {
		$parentCourseId = $topCourseId;
		if ($node['parentIdentifier'] !== null) {
			$parentCourseId = ($courseIdByOrgId[$node['parentIdentifier']] ?? $topCourseId);
		}

		if ($parentCourseId === null) {
			// A leaf item with no enclosing folder — materialise an implicit top-level Course first.
			$parentCourseId = $this->objectWriter->createCourse(title: 'Imported course', parentCourseId: null, tenantId: $tenantId);
			$topCourseId = $parentCourseId;
		}

		return $parentCourseId;
	}//end resolveParentCourseId()

	/**
	 * Resources not referenced by any organization item still get one entry
	 * each (e.g. a QTI item bank resource an assessment references directly,
	 * or an orphan resource with no manifest organization entry).
	 *
	 * @param array<int, array<string, mixed>> $resources Manifest resource rows.
	 * @param string $dir Extracted CC package directory.
	 * @param string|null $topCourseId Top-level Course UUID.
	 * @param string $importedBy NC user id.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function routeUnreferencedResources(
		array $resources,
		string $dir,
		?string $topCourseId,
		string $importedBy,
		string $tenantId,
		array &$entries,
	): void {
		$routedResourceIds = array_column($entries, 'resourceIdentifier');
		foreach ($resources as $resource) {
			if (in_array($resource['identifier'], $routedResourceIds, strict: true) === true) {
				continue;
			}

			$this->resourceRouter->routeResource(
				resource: $resource,
				dir: $dir,
				courseId: $topCourseId,
				lessonOrder: null,
				lessonTitle: null,
				importedBy: $importedBy,
				tenantId: $tenantId,
				entries: $entries,
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
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
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
