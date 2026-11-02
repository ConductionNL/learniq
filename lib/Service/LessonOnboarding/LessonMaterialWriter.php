<?php

/**
 * Learniq lesson onboarding material writer.
 *
 * @category Service
 * @package  OCA\Learniq\Service\LessonOnboarding
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
 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCP\Files\File;

/**
 * Stores an imported lesson's images in the tenant's files and links them,
 * and the original file, to the lesson as materials.
 *
 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
 */
final class LessonMaterialWriter {

	/**
	 * Constructor.
	 *
	 * @param CoursePackageObjectWriter $objectWriter Creates the materials.
	 * @param CoursePackageFileWriter   $fileWriter   Stores the image bytes.
	 */
	public function __construct(
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageFileWriter $fileWriter,
	) {
	}//end __construct()

	/**
	 * Write each section's images to the teacher's files and record them as Materials.
	 *
	 * @param list<array<string, mixed>> $sections The sections.
	 * @param File $file The source document (names the image files).
	 * @param array{userId: string, courseId: string, lessonId: string, tenantId: string} $context Where they belong.
	 *
	 * @return array<int, list<string>> Per section index, the Material ids.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function images(array $sections, File $file, array $context): array {
		$base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string)pathinfo((string)$file->getName(), PATHINFO_FILENAME));
		$context['prefix'] = trim((string)$base, '-') . '-' . (int)$file->getId() . '-';
		$materialIds = [];
		foreach ($sections as $index => $section) {
			foreach (($section['images'] ?? []) as $image) {
				$materialId = $this->imageMaterial(image: $image, context: $context);
				if ($materialId !== null) {
					$materialIds[$index][] = $materialId;
				}
			}
		}

		return $materialIds;
	}//end images()

	/**
	 * Store one image in the tenant's files and create its material.
	 *
	 * @param array<string,mixed> $image   The image: name and bytes.
	 * @param array<string,mixed> $context The ids, plus the file name prefix.
	 *
	 * @return string|null The material's UUID, or null when the image or its material could not be stored.
	 */
	private function imageMaterial(array $image, array $context): ?string {
		$fileRef = $this->fileWriter->writeBytesToFiles(
			content: (string)$image['bytes'],
			filename: $context['prefix'] . preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string)$image['name']),
			importedBy: $context['userId'],
			tenantId: $context['tenantId'],
		);
		if ($fileRef === null) {
			return null;
		}

		return $this->createMaterial(material: ['title' => (string)$image['name'], 'kind' => 'other', 'fileRef' => $fileRef], context: $context);
	}//end imageMaterial()

	/**
	 * Create a material of the new lesson.
	 *
	 * @param array{title: string, kind: string, fileRef: string} $material The material's own fields.
	 * @param array<string,mixed>                                 $context  The ids.
	 *
	 * @return string|null The material's UUID, or null when it was not created.
	 */
	private function createMaterial(array $material, array $context): ?string {
		return $this->objectWriter->create(
			schema: 'material',
			object: $material + ['courseId' => $context['courseId'], 'lessonId' => $context['lessonId'], 'tenant_id' => $context['tenantId']]
		);
	}//end createMaterial()

	/**
	 * Link the original file to the lesson as a Material.
	 *
	 * @param File $file The document or deck.
	 * @param string $format `docx` or `pptx`.
	 * @param array{userId: string, courseId: string, lessonId: string, tenantId: string} $context Where it belongs.
	 *
	 * @return string|null The Material id.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function original(File $file, string $format, array $context): ?string {
		$prefix = '/' . $context['userId'] . '/files';
		$path = (string)$file->getPath();
		if (str_starts_with($path, $prefix . '/') === true) {
			$path = substr($path, strlen($prefix));
		}

		$kind = 'document';
		if ($format === 'pptx') {
			$kind = 'slides';
		}

		return $this->createMaterial(material: ['title' => (string)$file->getName(), 'kind' => $kind, 'fileRef' => $path], context: $context);
	}//end original()
}//end class
