<?php

/**
 * Learniq Common Cartridge Resource Router
 *
 * Routes one IMS Common Cartridge resource to its Learniq target (Material /
 * QTI item / LTI placement / dropped), appending exactly one report entry per
 * source resource — nothing is ever silently dropped (the structural
 * anti-Canvas promise, see the proposal's "Why").
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing ZIP/tar/XML
 * from an external interchange format cannot be expressed declaratively.
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

use Psr\Log\LoggerInterface;

/**
 * Maps a single Common Cartridge resource onto Learniq objects + a report entry.
 */
class CommonCartridgeResourceRouter {
	/**
	 * Creates the Learniq objects the package materialises.
	 *
	 * @var CoursePackageObjectWriter
	 */
	private readonly CoursePackageObjectWriter $objectWriter;

	/**
	 * Resolves package-relative file bytes into nc:files.
	 *
	 * @var CoursePackageFileWriter
	 */
	private readonly CoursePackageFileWriter $fileWriter;

	/**
	 * Builds the report entry rows.
	 *
	 * @var CoursePackageImportReporter
	 */
	private readonly CoursePackageImportReporter $reporter;

	/**
	 * Constructor.
	 *
	 * @param CoursePackageOutput $output The object writer, file writer and report entry builder.
	 * @param PackageXmlValueReader $xmlReader Reads weblink side-car XML descriptors.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		CoursePackageOutput $output,
		private readonly PackageXmlValueReader $xmlReader,
		private readonly LoggerInterface $logger,
	) {
		$this->objectWriter = $output->objectWriter;
		$this->fileWriter = $output->fileWriter;
		$this->reporter = $output->reporter;
	}//end __construct()

	/**
	 * Route one CC resource and return its one report entry (for QTI
	 * resources a `pending-qti` placeholder, resolved in bulk later by
	 * `CommonCartridgeCourseImporter::importQtiResources()`).
	 *
	 * @param ResourceSlot   $slot    The resource and its Course and Lesson slot.
	 * @param PackageContext $package The extracted package, its importer and tenant.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	public function routeResource(ResourceSlot $slot, PackageContext $package): array {
		$resource = $slot->resource;
		if (str_contains(strtolower($resource['type']), 'scorm') === true) {
			return $this->droppedEntry(
				resource: $resource,
				reason: "requires ADR-002's lesson-content importer, not yet implemented"
			);
		}

		try {
			return $this->buildResourceEntry(slot: $slot, package: $package);
		} catch (\Throwable $e) {
			// A per-resource failure never aborts the whole import — it becomes a
			// dropped entry so the report still names every resource (never a
			// silent absence, and never a partially-created object either).
			$this->logger->warning(
				'[CommonCartridgeResourceRouter] Resource {id} failed to import: {msg}',
				['id' => $resource['identifier'], 'msg' => $e->getMessage()]
			);
			return $this->droppedEntry(resource: $resource, reason: 'Import failed: ' . $e->getMessage());
		}//end try
	}//end routeResource()

	/**
	 * Materialise one resource by its manifest classification and return its report entry.
	 *
	 * @param ResourceSlot   $slot    The resource and its Course and Lesson slot.
	 * @param PackageContext $package The extracted package, its importer and tenant.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function buildResourceEntry(ResourceSlot $slot, PackageContext $package): array {
		$resource = $slot->resource;
		$classification = $resource['classification'];

		if ($classification === 'webcontent') {
			return $this->importWebContent(slot: $slot, package: $package);
		}

		if ($classification === 'weblink') {
			return $this->importWebLink(slot: $slot, package: $package);
		}

		if ($classification === 'imsqti_item' || $classification === 'imsqti_test') {
			return $this->pendingQtiEntry(resource: $resource);
		}

		if ($classification === 'basiclti') {
			return $this->importBasicLti(resource: $resource, courseId: $slot->courseId, tenantId: $package->tenantId);
		}

		if ($classification === 'discussion') {
			return $this->droppedEntry(
				resource: $resource,
				reason: 'No Learniq schema represents discussion/forum content — migrate manually.'
			);
		}

		return $this->droppedEntry(resource: $resource, reason: "Resource type not supported: {$resource['type']}.");
	}//end buildResourceEntry()

	/**
	 * Build the `pending-qti` placeholder entry for a QTI/CC assessment resource.
	 *
	 * QTI/CC assessment items are imported in bulk (one ItemBank per package) by
	 * `CommonCartridgeCourseImporter::importQtiResources()`, called once after the
	 * resource walk; this placeholder only guards against double-handling and is
	 * rewritten to its final outcome there.
	 *
	 * @param array<string, mixed> $resource One `CommonCartridgeParser` resource row.
	 *
	 * @return array<string, mixed> The placeholder report entry for this resource.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function pendingQtiEntry(array $resource): array {
		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			result: ['outcome' => 'pending-qti', 'targetType' => null, 'targetId' => null, 'reason' => null]
		);
	}//end pendingQtiEntry()

	/**
	 * Materialise a `webcontent` resource as a Material plus its owning Lesson.
	 *
	 * @param ResourceSlot   $slot    The resource and its Course and Lesson slot.
	 * @param PackageContext $package The extracted package, its importer and tenant.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importWebContent(ResourceSlot $slot, PackageContext $package): array {
		$resource = $slot->resource;
		$materialId = $this->objectWriter->createMaterial(
			material: [
				'title' => $slot->lessonTitle ?? $resource['title'],
				'kind' => 'document',
				'fileRef' => $this->fileWriter->resolveFileRef(
				dir: $package->dir,
				href: $resource['href'],
				importedBy: $package->importedBy,
				tenantId: $package->tenantId
			),
				'url' => null,
				'courseId' => $slot->courseId,
			],
			tenantId: $package->tenantId
		);
		try {
			$this->objectWriter->createLesson(
				lesson: [
					'courseId' => $slot->courseId,
					'title' => $slot->lessonTitle ?? $resource['title'],
					'order' => $slot->lessonOrder ?? 0,
					'contentType' => 'text',
					'contentRef' => (string)$materialId,
				],
				tenantId: $package->tenantId
			);
		} catch (\Throwable $e) {
			// The resource is reported dropped, so its Material must not stay behind.
			$this->discardMaterial(materialId: $materialId);
			throw $e;
		}

		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			result: ['outcome' => 'imported', 'targetType' => 'material', 'targetId' => $materialId, 'reason' => null]
		);
	}//end importWebContent()

	/**
	 * Remove a Material whose Lesson could not be created; a failed removal is logged.
	 *
	 * @param string|null $materialId The created Material UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function discardMaterial(?string $materialId): void {
		if ($materialId === null) {
			return;
		}

		try {
			$this->objectWriter->delete(schema: 'material', uuid: $materialId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[CommonCartridgeResourceRouter] Material {id} of a dropped resource could not be removed: {msg}',
				['id' => $materialId, 'msg' => $e->getMessage()]
			);
		}
	}//end discardMaterial()

	/**
	 * Materialise a `weblink` resource as a `link`-kind Material.
	 *
	 * @param ResourceSlot   $slot    The resource and its Course and Lesson slot.
	 * @param PackageContext $package The extracted package, its importer and tenant.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importWebLink(ResourceSlot $slot, PackageContext $package): array {
		$resource = $slot->resource;
		$materialId = $this->objectWriter->createMaterial(
			material: [
				'title' => $slot->lessonTitle ?? $resource['title'],
				'kind' => 'link',
				'fileRef' => null,
				'url' => $this->resolveWeblinkUrl(dir: $package->dir, href: $resource['href']),
				'courseId' => $slot->courseId,
			],
			tenantId: $package->tenantId
		);

		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			result: ['outcome' => 'imported', 'targetType' => 'material', 'targetId' => $materialId, 'reason' => null]
		);
	}//end importWebLink()

	/**
	 * Materialise a `basiclti` resource as a degraded LtiToolPlacement.
	 *
	 * @param array<string, mixed> $resource One `CommonCartridgeParser` resource row.
	 * @param string|null $courseId Enclosing Course UUID, when known.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-an-lti-resource-becomes-a-placement-not-an-inline-link
	 */
	private function importBasicLti(array $resource, ?string $courseId, string $tenantId): array {
		$placementId = $this->objectWriter->createLtiPlacement(courseId: $courseId, tenantId: $tenantId);

		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			result: [
				'outcome' => 'degraded',
				'targetType' => 'lti-tool-placement',
				'targetId' => $placementId,
				'reason' => 'LTI placement created without a configured OpenConnector deployment; '
				. 'an admin must bind a deployment before this tool can be launched.',
			]
		);
	}//end importBasicLti()

	/**
	 * Build a `dropped` report entry for a resource.
	 *
	 * @param array<string, mixed> $resource One `CommonCartridgeParser` resource row.
	 * @param string|null $reason Human-readable drop reason.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function droppedEntry(array $resource, ?string $reason): array {
		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			result: ['outcome' => 'dropped', 'targetType' => null, 'targetId' => null, 'reason' => $reason]
		);
	}//end droppedEntry()

	/**
	 * Resolve a CC weblink resource's actual target URL. A CC `imswl` resource's
	 * manifest `href` points at the local weblink XML file, not the target URL —
	 * the real URL lives inside that file as `<webLink><url href="..."/>`. Falls
	 * back to the manifest href itself when the file is missing or unparseable,
	 * so a Material is still created rather than left with no url at all.
	 *
	 * @param string $dir Extracted package directory.
	 * @param string|null $href Package-relative path to the weblink XML file.
	 *
	 * @return string|null The resolved target URL, or null when nothing could be resolved.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function resolveWeblinkUrl(string $dir, ?string $href): ?string {
		if ($href === null) {
			return null;
		}

		$resolved = $this->xmlReader->readAttribute(path: $dir . '/' . $href, tagName: 'url', attribute: 'href');
		if ($resolved === null) {
			return $href;
		}

		return $resolved;
	}//end resolveWeblinkUrl()
}//end class
