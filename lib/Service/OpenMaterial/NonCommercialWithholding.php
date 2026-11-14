<?php

/**
 * Learniq Non-Commercial Withholding
 *
 * Keeps `Material.withheldReason` in step with the course: non-commercial (NC)
 * material in a paid or corporate course is marked `non-commercial`, which the
 * Material read rule refuses to learners, and the mark is cleared again once
 * the course is free or the material is no longer NC.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
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
 * @spec openspec/changes/open-learning-material
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Marks the NC materials of one course as withheld from learners, or clears the mark.
 *
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 */
class NonCommercialWithholding {

	/**
	 * The reason stored on NC material in a commercial course.
	 */
	public const REASON = 'non-commercial';

	private const MATERIAL_SCHEMA = 'material';

	private const COURSE_SCHEMA = 'course';

	private const MAX_MATERIALS = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService         $objectService Reads the course and its materials, writes the mark.
	 * @param CommercialCourseCheck $commercial    Tells whether the course is paid or corporate.
	 * @param LoggerInterface       $logger        Logs reads and writes that fail.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CommercialCourseCheck $commercial,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Bring the withheld mark of every material of one course in line with the course.
	 *
	 * A course that cannot be read changes nothing: not knowing whether it is
	 * paid is no reason to serve or to withhold.
	 *
	 * @param string $courseId The course UUID.
	 *
	 * @return int The number of materials written.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
	 */
	public function syncCourse(string $courseId): int {
		if ($courseId === '') {
			return 0;
		}

		try {
			$course = $this->first(schema: self::COURSE_SCHEMA, config: ['ids' => [$courseId], 'limit' => 1]);
			if ($course === null) {
				return 0;
			}

			$course['id'] = (string)($course['id'] ?? $courseId);
			$commercial   = $this->commercial->isCommercial(course: $course);
			$materials    = $this->rows(
				schema: self::MATERIAL_SCHEMA,
				config: ['filters' => ['courseId' => $courseId], 'limit' => self::MAX_MATERIALS]
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[NonCommercialWithholding] Course {course} could not be read: {msg}',
				['course' => $courseId, 'msg' => $exception->getMessage()]
			);
			return 0;
		}

		$written = 0;
		foreach ($materials as $material) {
			if ($this->apply(material: $material, reason: $this->reasonFor(material: $material, commercial: $commercial)) === true) {
				$written++;
			}
		}

		return $written;
	}//end syncCourse()

	/**
	 * The reason a material should carry: `non-commercial` for NC material in a commercial course, else empty.
	 *
	 * @param array<string, mixed> $material   The serialised material.
	 * @param bool                 $commercial Whether its course is paid or corporate.
	 *
	 * @return string The reason, or '' when it is served.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
	 */
	public function reasonFor(array $material, bool $commercial): string {
		if ($commercial === true && ($material['nonCommercial'] ?? false) === true) {
			return self::REASON;
		}

		return '';
	}//end reasonFor()

	/**
	 * Write the reason on one material when it differs from the stored one.
	 *
	 * @param array<string, mixed> $material The serialised material.
	 * @param string               $reason   The reason it should carry.
	 *
	 * @return bool True when the material was written.
	 */
	private function apply(array $material, string $reason): bool {
		$uuid = ($material['id'] ?? ($material['uuid'] ?? null));
		if (is_string($uuid) === false || $uuid === '' || (string)($material['withheldReason'] ?? '') === $reason) {
			return false;
		}

		// The row as read carries OpenRegister's `@self` block; saving it back
		// would make OpenRegister check the acting user's folder rights.
		$object = array_merge($material, ['withheldReason' => $reason]);
		unset($object['@self']);

		try {
			$this->objectService->saveObject(
				object: $object,
				register: AppIdentity::APP_ID,
				schema: self::MATERIAL_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[NonCommercialWithholding] Could not mark material {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return false;
		}

		return true;
	}//end apply()

	/**
	 * The first row of a read, or null.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $config The findAll config without register and schema.
	 *
	 * @return array<string, mixed>|null The serialised row.
	 */
	private function first(string $schema, array $config): ?array {
		return ($this->rows(schema: $schema, config: $config)[0] ?? null);
	}//end first()

	/**
	 * Read rows of one schema, unscoped, as arrays.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $config The findAll config without register and schema.
	 *
	 * @return array<int, array<string, mixed>> The serialised rows.
	 */
	private function rows(string $schema, array $config): array {
		$config['filters'] = array_merge(
			($config['filters'] ?? []),
			['register' => AppIdentity::APP_ID, 'schema' => $schema]
		);
		$rows = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);

		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end rows()
}//end class
