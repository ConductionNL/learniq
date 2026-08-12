<?php

/**
 * Scholiq Common Cartridge Resource Router
 *
 * Routes one IMS Common Cartridge resource to its scholiq target (Material /
 * QTI item / LTI placement / dropped), appending exactly one report entry per
 * source resource — nothing is ever silently dropped (the structural
 * anti-Canvas promise, see the proposal's "Why").
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing ZIP/tar/XML
 * from an external interchange format cannot be expressed declaratively.
 *
 * @category Service
 * @package  OCA\Scholiq\Service\CoursePackage
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
 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service\CoursePackage;

use Psr\Log\LoggerInterface;

/**
 * Maps a single Common Cartridge resource onto scholiq objects + a report entry.
 */
class CommonCartridgeResourceRouter {
	/**
	 * Constructor.
	 *
	 * @param CoursePackageObjectWriter $objectWriter Creates the scholiq objects a resource materialises.
	 * @param CoursePackageFileWriter $fileWriter Resolves package-relative file bytes into nc:files.
	 * @param CoursePackageImportReporter $reporter Builds the report entry rows.
	 * @param PackageXmlValueReader $xmlReader Reads weblink side-car XML descriptors.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageFileWriter $fileWriter,
		private readonly CoursePackageImportReporter $reporter,
		private readonly PackageXmlValueReader $xmlReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Route one CC resource, appending exactly one report entry (for QTI
	 * resources a `pending-qti` placeholder, resolved in bulk later by
	 * `CommonCartridgeCourseImporter::importQtiResources()`).
	 *
	 * @param array<string, mixed> $resource One `CommonCartridgeParser` resource row.
	 * @param string $dir Extracted package directory (for file resolution).
	 * @param string|null $courseId Enclosing Course UUID, when known.
	 * @param int|null $lessonOrder Manifest order, when this resource has an owning Lesson slot.
	 * @param string|null $lessonTitle Lesson title, when this resource has an owning Lesson slot.
	 * @param string $importedBy NC user id (nc:files owner for resolved Material bytes).
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	public function routeResource(
		array $resource,
		string $dir,
		?string $courseId,
		?int $lessonOrder,
		?string $lessonTitle,
		string $importedBy,
		string $tenantId,
		array &$entries,
	): void {
		if (str_contains(strtolower($resource['type']), 'scorm') === true) {
			$entries[] = $this->droppedEntry(
				resource: $resource,
				reason: "requires ADR-002's lesson-content importer, not yet implemented"
			);
			return;
		}

		try {
			$entries[] = $this->buildResourceEntry(
				resource: $resource,
				dir: $dir,
				courseId: $courseId,
				lessonOrder: $lessonOrder,
				lessonTitle: $lessonTitle,
				importedBy: $importedBy,
				tenantId: $tenantId,
			);
		} catch (\Throwable $e) {
			// A per-resource failure never aborts the whole import — it becomes a
			// dropped entry so the report still names every resource (never a
			// silent absence, and never a partially-created object either).
			$this->logger->warning(
				'[CommonCartridgeResourceRouter] Resource {id} failed to import: {msg}',
				['id' => $resource['identifier'], 'msg' => $e->getMessage()]
			);
			$entries[] = $this->droppedEntry(resource: $resource, reason: 'Import failed: ' . $e->getMessage());
		}//end try
	}//end routeResource()

	/**
	 * Materialise one resource by its manifest classification and return its report entry.
	 *
	 * @param array<string, mixed> $resource One `CommonCartridgeParser` resource row.
	 * @param string $dir Extracted package directory.
	 * @param string|null $courseId Enclosing Course UUID, when known.
	 * @param int|null $lessonOrder Manifest order, when this resource has an owning Lesson slot.
	 * @param string|null $lessonTitle Lesson title, when this resource has an owning Lesson slot.
	 * @param string $importedBy NC user id (nc:files owner for resolved Material bytes).
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function buildResourceEntry(
		array $resource,
		string $dir,
		?string $courseId,
		?int $lessonOrder,
		?string $lessonTitle,
		string $importedBy,
		string $tenantId,
	): array {
		$classification = $resource['classification'];

		if ($classification === 'webcontent') {
			return $this->importWebContent(
				resource: $resource,
				dir: $dir,
				courseId: $courseId,
				lessonOrder: $lessonOrder,
				lessonTitle: $lessonTitle,
				importedBy: $importedBy,
				tenantId: $tenantId,
			);
		}

		if ($classification === 'weblink') {
			return $this->importWebLink(
				resource: $resource,
				dir: $dir,
				courseId: $courseId,
				lessonTitle: $lessonTitle,
				tenantId: $tenantId,
			);
		}

		if ($classification === 'imsqti_item' || $classification === 'imsqti_test') {
			return $this->pendingQtiEntry(resource: $resource);
		}

		if ($classification === 'basiclti') {
			return $this->importBasicLti(resource: $resource, courseId: $courseId, tenantId: $tenantId);
		}

		if ($classification === 'discussion') {
			return $this->droppedEntry(
				resource: $resource,
				reason: 'No scholiq schema represents discussion/forum content — migrate manually.'
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
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function pendingQtiEntry(array $resource): array {
		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			outcome: 'pending-qti',
			targetType: null,
			targetId: null,
			reason: null,
		);
	}//end pendingQtiEntry()

	/**
	 * Materialise a `webcontent` resource as a Material plus its owning Lesson.
	 *
	 * @param array<string, mixed> $resource One `CommonCartridgeParser` resource row.
	 * @param string $dir Extracted package directory.
	 * @param string|null $courseId Enclosing Course UUID, when known.
	 * @param int|null $lessonOrder Manifest order, when this resource has an owning Lesson slot.
	 * @param string|null $lessonTitle Lesson title, when this resource has an owning Lesson slot.
	 * @param string $importedBy NC user id (nc:files owner for resolved Material bytes).
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importWebContent(
		array $resource,
		string $dir,
		?string $courseId,
		?int $lessonOrder,
		?string $lessonTitle,
		string $importedBy,
		string $tenantId,
	): array {
		$materialId = $this->objectWriter->createMaterial(
			title: $lessonTitle ?? $resource['title'],
			kind: 'document',
			fileRef: $this->fileWriter->resolveFileRef(dir: $dir, href: $resource['href'], importedBy: $importedBy, tenantId: $tenantId),
			url: null,
			courseId: $courseId,
			tenantId: $tenantId,
		);
		$this->objectWriter->createLesson(
			courseId: $courseId,
			title: $lessonTitle ?? $resource['title'],
			order: $lessonOrder ?? 0,
			contentType: 'text',
			contentRef: (string)$materialId,
			tenantId: $tenantId
		);

		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			outcome: 'imported',
			targetType: 'material',
			targetId: $materialId,
			reason: null,
		);
	}//end importWebContent()

	/**
	 * Materialise a `weblink` resource as a `link`-kind Material.
	 *
	 * @param array<string, mixed> $resource One `CommonCartridgeParser` resource row.
	 * @param string $dir Extracted package directory.
	 * @param string|null $courseId Enclosing Course UUID, when known.
	 * @param string|null $lessonTitle Lesson title, when this resource has an owning Lesson slot.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this resource.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importWebLink(array $resource, string $dir, ?string $courseId, ?string $lessonTitle, string $tenantId): array {
		$materialId = $this->objectWriter->createMaterial(
			title: $lessonTitle ?? $resource['title'],
			kind: 'link',
			fileRef: null,
			url: $this->resolveWeblinkUrl(dir: $dir, href: $resource['href']),
			courseId: $courseId,
			tenantId: $tenantId,
		);

		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			outcome: 'imported',
			targetType: 'material',
			targetId: $materialId,
			reason: null,
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
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-an-lti-resource-becomes-a-placement-not-an-inline-link
	 */
	private function importBasicLti(array $resource, ?string $courseId, string $tenantId): array {
		$placementId = $this->objectWriter->createLtiPlacement(courseId: $courseId, tenantId: $tenantId);

		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			outcome: 'degraded',
			targetType: 'lti-tool-placement',
			targetId: $placementId,
			reason: 'LTI placement created without a configured OpenConnector deployment; '
				. 'an admin must bind a deployment before this tool can be launched.',
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
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function droppedEntry(array $resource, ?string $reason): array {
		return $this->reporter->entry(
			resourceIdentifier: $resource['identifier'],
			resourceType: $resource['type'],
			title: $resource['title'],
			outcome: 'dropped',
			targetType: null,
			targetId: null,
			reason: $reason,
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
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
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
