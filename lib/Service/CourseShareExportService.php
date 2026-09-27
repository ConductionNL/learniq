<?php

/**
 * Learniq Course Share Export Service
 *
 * Produces the package that may leave the school. It reads the course tree
 * through the regular exporter (so the same RBAC-applying reads run), asks the
 * sharing gate, strips the payload, adds the sharing metadata, and records
 * who confirmed what in a CourseShareConsent before handing the package back.
 * A consent that cannot be recorded fails the export: the record is the
 * school's answer to "who let this leave".
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-every-share-export-leaves-a-consent-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Exception\SharingBlockedException;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Gate, strip, record, return.
 */
class CourseShareExportService {

	private const LEARNIQ_REGISTER = 'learniq';

	public const CONSENT_SCHEMA = 'course-share-consent';

	public const PURPOSE_DOWNLOAD = 'download';

	public const PURPOSE_STORE = 'store';

	/**
	 * Constructor.
	 *
	 * @param CoursePackageExportService $exporter      Reads the course tree and builds the base payload.
	 * @param CourseSharingGate          $gate          Decides whether the course may leave.
	 * @param CourseSharePackageBuilder  $builder       Strips the payload and adds the sharing block.
	 * @param ObjectService              $objectService Writes the consent record.
	 */
	public function __construct(
		private readonly CoursePackageExportService $exporter,
		private readonly CourseSharingGate $gate,
		private readonly CourseSharePackageBuilder $builder,
		private readonly ObjectService $objectService,
	) {

	}//end __construct()

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
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-every-share-export-leaves-a-consent-record
	 */
	public function buildPackage(
		string $courseId,
		string $userId,
		bool $noPupilData,
		bool $rightsCleared,
		string $purpose=self::PURPOSE_DOWNLOAD
	): array {
		$tree = $this->exporter->gatherCourseTree(courseId: $courseId, exportingUser: $userId);

		$blockers = $this->gate->check(
			course: (array)$tree['course'],
			lessons: (array)$tree['lessons'],
			materials: (array)$tree['materials'],
			noPupilData: $noPupilData,
			rightsCleared: $rightsCleared
		);
		if ($blockers !== []) {
			throw new SharingBlockedException(blockers: $blockers);
		}

		$sharedAt = gmdate('c');
		$package  = $this->builder->build(payload: $this->exporter->toScholiqPayload(tree: $tree), sharedAt: $sharedAt);

		$this->recordConsent(course: (array)$tree['course'], courseId: $courseId, userId: $userId, sharedAt: $sharedAt, purpose: $purpose);

		return $package;

	}//end buildPackage()

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
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
	 */
	public function export(string $courseId, string $userId, bool $noPupilData, bool $rightsCleared): array {
		$package = $this->buildPackage(
			courseId: $courseId,
			userId: $userId,
			noPupilData: $noPupilData,
			rightsCleared: $rightsCleared
		);

		return [
			'content'     => (string)json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'filename'    => 'course-' . $courseId . '_share.json',
			'contentType' => 'application/json',
		];

	}//end export()

	/**
	 * Write the CourseShareConsent; fail when it cannot be written.
	 *
	 * @param array<string, mixed> $course   The course object.
	 * @param string               $courseId UUID of the course.
	 * @param string               $userId   Nextcloud user id of whoever confirmed.
	 * @param string               $sharedAt ISO 8601 moment of sharing.
	 * @param string               $purpose  `download` or `store`.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the record is not saved.
	 */
	private function recordConsent(array $course, string $courseId, string $userId, string $sharedAt, string $purpose): void {
		$record = [
			'courseId'      => $courseId,
			'courseName'    => (string)($course['name'] ?? ''),
			'purpose'       => $purpose,
			'confirmedBy'   => $userId,
			'confirmedAt'   => $sharedAt,
			'noPupilData'   => true,
			'rightsCleared' => true,
			'license'       => (string)($course['license'] ?? ''),
		];
		if (isset($course['tenant_id']) === true && is_string($course['tenant_id']) === true) {
			$record['tenant_id'] = $course['tenant_id'];
		}

		try {
			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: self::CONSENT_SCHEMA,
				object: $record
			);
		} catch (\Throwable $e) {
			throw new RuntimeException(
				message: 'The share consent could not be recorded: ' . $e->getMessage(),
				code: 0,
				previous: $e
			);
		}

	}//end recordConsent()
}//end class
