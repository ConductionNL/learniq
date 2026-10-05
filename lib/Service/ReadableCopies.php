<?php

/**
 * Learniq ReadableCopies
 *
 * Derives the readable copies a portal shows instead of a uuid: the course
 * name on a grade, the group name on an enrolment, and the portfolio title and
 * learner name on a portfolio share, and the teacher's display name on a
 * teacher availability. The portal joins one hop at most and
 * never reads a course, a cohort or a learner profile for these readers, so
 * the server writes the names on the row itself (site-guardian-portal-design,
 * site-external-assessor-portal-design). A Nextcloud user is no register
 * object a list can resolve, so the teacher's name is written on the
 * availability too (teacher-availability-reads-words).
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;

/**
 * Names the objects a row points at, for the schemas that carry a readable copy.
 *
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
 */
class ReadableCopies {

	private const REGISTER = 'learniq';

	/**
	 * The copies each schema carries, empty when nothing is known.
	 *
	 * @var array<string, array<string, null>>
	 */
	private const EMPTY = [
		'grade-entry'     => ['courseName' => null],
		// The employer's portal reads a participant's name, course and company on the row (employer-portal-audience).
		'enrolment'       => ['cohortName' => null, 'learnerName' => null, 'courseName' => null, 'organisationRef' => null],
		'portfolio-share' => ['portfolioTitle' => null, 'learnerName' => null],
		'teacher-availability' => ['teacherName' => null],
		// The line under a child's name in the guardian's menu (school-portals-use-the-new-blocks).
		'learner-profile' => ['groupLabel' => null, 'fullName' => null],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the named objects.
	 * @param IUserManager  $users         Names a Nextcloud user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * Whether a schema carries readable copies.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 */
	public function covers(string $slug): bool {
		return array_key_exists($slug, self::EMPTY);
	}//end covers()

	/**
	 * The copy field names of a schema.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 */
	public function fields(string $slug): array {
		return array_keys((self::EMPTY[$slug] ?? []));
	}//end fields()

	/**
	 * The readable copies for one row of a covered schema.
	 *
	 * A pointer that is empty or names nothing gives null for its copy.
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $row  The row as it will be stored.
	 *
	 * @return array<string, string|null>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
	 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-new-a-share-names-its-candidate-and-portfolio
	 * @spec openspec/changes/teacher-availability-reads-words/specs/parent-conferences/spec.md#requirement-the-teacher-availability-list-reads-words
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
	 */
	public function derive(string $slug, array $row): array {
		if ($slug === 'grade-entry') {
			return ['courseName' => $this->nameOf(schema: 'course', id: $row['courseId'] ?? null, field: 'name')];
		}

		if ($slug === 'enrolment') {
			return array_merge(
				['cohortName' => $this->nameOf(schema: 'cohort', id: $row['cohortId'] ?? null, field: 'name')],
				$this->participantCopies(row: $row)
			);
		}

		if ($slug === 'portfolio-share') {
			return $this->shareCopies(portfolioId: $row['portfolioId'] ?? null);
		}

		if ($slug === 'teacher-availability') {
			return ['teacherName' => $this->userName(uid: $row['teacherId'] ?? null)];
		}

		if ($slug === 'learner-profile') {
			return [
				'groupLabel' => (new LearnerGroupLabel(objectService: $this->objectService, users: $this->users))->derive(profile: $row),
				'fullName'   => $this->personName(profile: $row),
			];
		}

		return [];
	}//end derive()

	/**
	 * The participant's name, the course's name and the participant's employer, for an enrolment.
	 *
	 * @param array<string, mixed> $row The enrolment as it will be stored.
	 *
	 * @return array{learnerName: string|null, courseName: string|null, organisationRef: string|null}
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-reads-only-her-own-companys-people-and-bookings
	 */
	private function participantCopies(array $row): array {
		$learner = $this->read(schema: 'learner-profile', id: $row['learnerRef'] ?? null);
		$organisation = null;
		if ($learner !== null) {
			$organisation = $this->orNull(value: $this->text(value: $learner['organisationRef'] ?? null));
		}

		return [
			'learnerName'     => $learner === null ? null : $this->personName(profile: $learner),
			'courseName'      => $this->nameOf(schema: 'course', id: $row['courseId'] ?? null, field: 'name'),
			'organisationRef' => $organisation,
		];
	}//end participantCopies()

	/**
	 * Given and family name as one line, or null.
	 *
	 * @param array<string, mixed> $profile A learner profile.
	 *
	 * @return string|null
	 */
	private function personName(array $profile): ?string {
		return $this->orNull(value: trim($this->text(value: $profile['givenName'] ?? null) . ' ' . $this->text(value: $profile['familyName'] ?? null)));
	}//end personName()

	/**
	 * The portfolio title and the learner's name for a share.
	 *
	 * @param mixed $portfolioId The share's portfolio pointer.
	 *
	 * @return array{portfolioTitle: string|null, learnerName: string|null}
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function shareCopies(mixed $portfolioId): array {
		$portfolio = $this->read(schema: 'portfolio', id: $portfolioId);
		if ($portfolio === null) {
			return ['portfolioTitle' => null, 'learnerName' => null];
		}

		$learner = $this->read(schema: 'learner-profile', id: $portfolio['learnerRef'] ?? null);
		$name = null;
		if ($learner !== null) {
			$name = $this->orNull(value: trim($this->text(value: $learner['givenName'] ?? null) . ' ' . $this->text(value: $learner['familyName'] ?? null)));
		}

		return [
			'portfolioTitle' => $this->orNull(value: trim($this->text(value: $portfolio['title'] ?? null))),
			'learnerName'    => $name,
		];
	}//end shareCopies()

	/**
	 * A Nextcloud user's display name, or null for an empty or unknown uid.
	 *
	 * @param mixed $uid The user id.
	 *
	 * @return string|null
	 */
	private function userName(mixed $uid): ?string {
		if (is_string($uid) === false || $uid === '') {
			return null;
		}

		return $this->orNull(value: trim((string)$this->users->getDisplayName($uid)));
	}//end userName()

	/**
	 * One text field of the object a pointer names, or null.
	 *
	 * @param string $schema The schema slug of the named object.
	 * @param mixed  $id     The pointer.
	 * @param string $field  The field to read.
	 *
	 * @return string|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function nameOf(string $schema, mixed $id, string $field): ?string {
		$object = $this->read(schema: $schema, id: $id);
		if ($object === null) {
			return null;
		}

		return $this->orNull(value: trim($this->text(value: $object[$field] ?? null)));
	}//end nameOf()

	/**
	 * The object with this uuid in a learniq schema, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param mixed  $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function read(string $schema, mixed $id): ?array {
		if (is_string($id) === false || $id === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => $schema,
				],
				'ids'     => [$id],
				'limit'   => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end read()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object An array or a serialisable entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toRow()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()

	/**
	 * The text, or null when it is empty.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
