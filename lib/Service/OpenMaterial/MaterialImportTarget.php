<?php

/**
 * Learniq Material Import Target
 *
 * Resolves the lesson or course a teacher adds open material to: the target
 * must exist and belong to the caller's tenant, and its course tells whether
 * the use is commercial.
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Service\CallerTenantResolver;
use OCP\IUser;

/**
 * The lesson or course a Material is added to.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */
class MaterialImportTarget {

	/**
	 * Constructor.
	 *
	 * @param CallerTenantResolver  $tenants    Finds rows in the caller's tenant only.
	 * @param CommercialCourseCheck $commercial Tells whether the course is commercial.
	 */
	public function __construct(
		private readonly CallerTenantResolver $tenants,
		private readonly CommercialCourseCheck $commercial,
	) {
	}//end __construct()

	/**
	 * The target's Material fields and whether its course is commercial.
	 *
	 * @param IUser                $user    The teacher.
	 * @param array<string, mixed> $request The add request: lessonId or courseId.
	 *
	 * @return array{fields: array<string, string>, commercial: bool} The target.
	 *
	 * @throws MaterialImportRefusedException When the target is missing or not the caller's.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function resolve(IUser $user, array $request): array {
		$lessonId = trim((string)($request['lessonId'] ?? ''));
		$fields   = [];
		if ($lessonId !== '') {
			$lesson = $this->owned(user: $user, ref: ['id' => $lessonId, 'schema' => 'lesson']);
			$fields = ['lessonId' => $lessonId, 'tenant_id' => (string)($lesson['tenant_id'] ?? '')];
			$request['courseId'] = (string)($lesson['courseId'] ?? '');
		}

		$course = $this->course(user: $user, courseId: trim((string)($request['courseId'] ?? '')));
		$fields = array_merge(['courseId' => $course['id'], 'tenant_id' => (string)($course['tenant_id'] ?? '')], array_filter($fields));

		return ['fields' => $fields, 'commercial' => $this->commercial->isCommercial(course: $course)];
	}//end resolve()

	/**
	 * The course, required: a Material always belongs to one.
	 *
	 * @param IUser  $user     The teacher.
	 * @param string $courseId The course UUID.
	 *
	 * @return array<string, mixed> The serialised course with its id.
	 *
	 * @throws MaterialImportRefusedException When the course is missing or not the caller's.
	 */
	private function course(IUser $user, string $courseId): array {
		$course       = $this->owned(user: $user, ref: ['id' => $courseId, 'schema' => 'course']);
		$course['id'] = (string)($course['id'] ?? $course['@self']['id'] ?? $courseId);

		return $course;
	}//end course()

	/**
	 * One row in the caller's tenant.
	 *
	 * @param IUser                              $user The teacher.
	 * @param array{id: string, schema: string} $ref  The row's id and schema.
	 *
	 * @return array<string, mixed> The serialised row.
	 *
	 * @throws MaterialImportRefusedException When the row is missing or not the caller's.
	 */
	private function owned(IUser $user, array $ref): array {
		$row = null;
		if ($ref['id'] !== '') {
			$row = $this->tenants->findOwned(user: $user, id: $ref['id'], schema: $ref['schema']);
		}

		if ($row === null) {
			throw new MaterialImportRefusedException(
				reasonCode: 'target-not-found',
				reason: 'The ' . $ref['schema'] . ' to add the material to was not found.'
			);
		}

		return $row;
	}//end owned()
}//end class
