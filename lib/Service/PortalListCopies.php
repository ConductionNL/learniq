<?php

/**
 * Learniq PortalListCopies
 *
 * The readable copies the school portals' person blocks and lists read
 * (portaliq resident-menu-follows-the-boards, mijn-lists-follow-the-boards):
 *
 * - a pupil's level and class, "4 havo" and "klas H4b" (`learner-profile`);
 * - the subject of a homework assignment (`assignment.courseName`);
 * - the teacher who gave a grade, and whether the grade is new: visible in
 *   the last seven days (`grade-entry.teacherName`, `grade-entry.isNew`);
 * - a company's employee count and how it signs in, "4 medewerkers" and
 *   "via eHerkenning" (`client-organisation`).
 *
 * ReadableCopies stamps them with its own copies on every save; this class
 * only derives them. In the school's own language, like every copy.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-lists-read-the-subject-the-teacher-and-what-is-new
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Throwable;

/**
 * Derives the copies of the person blocks and the lists.
 *
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-lists-read-the-subject-the-teacher-and-what-is-new
 */
class PortalListCopies {

	private const REGISTER = 'learniq';

	/**
	 * How long a grade reads "Nieuw" after it became visible.
	 */
	public const NEW_FOR_DAYS = 7;

	/**
	 * The copies each schema gets here, empty when nothing is known.
	 *
	 * @var array<string, array<string, null>>
	 */
	public const EMPTY = [
		'learner-profile' => ['levelLabel' => null, 'classLabel' => null],
		'assignment' => ['courseName' => null],
		'grade-entry' => ['teacherName' => null, 'isNew' => null],
		'client-organisation' => ['employeeCountLabel' => null, 'signInLabel' => null],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the named objects.
	 * @param IUserManager  $users         Names a teacher.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * The copies for one row of a schema this class covers, else [].
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $row  The row as it will be stored.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-lists-read-the-subject-the-teacher-and-what-is-new
	 */
	public function derive(string $slug, array $row): array {
		return match ($slug) {
			'learner-profile' => (new LearnerLevelLabel(
				objectService: $this->objectService,
				groups: new LearnerGroupLabel(objectService: $this->objectService, users: $this->users)
			))->derive(profile: $row),
			'assignment' => ['courseName' => $this->field(schema: 'course', id: ($row['courseId'] ?? null), field: 'name')],
			'grade-entry' => [
				'teacherName' => $this->userName(uid: ($row['grader'] ?? null)),
				'isNew' => self::isNew(row: $row, now: new DateTimeImmutable()),
			],
			'client-organisation' => $this->companyLines(row: $row),
			default => [],
		};
	}//end derive()

	/**
	 * Whether a grade is new: it became visible (its `visibleFrom`, else its
	 * `gradedAt`) in the last seven days, or it is not visible yet and so
	 * will be new when it shows.
	 *
	 * @param array<string, mixed> $row The grade.
	 * @param DateTimeImmutable    $now Now.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-lists-read-the-subject-the-teacher-and-what-is-new
	 */
	public static function isNew(array $row, DateTimeImmutable $now): bool {
		$moment = (string)($row['visibleFrom'] ?? '');
		if ($moment === '') {
			$moment = (string)($row['gradedAt'] ?? '');
		}

		try {
			$visible = new DateTimeImmutable($moment);
		} catch (Throwable) {
			return false;
		}

		if ($moment === '') {
			return false;
		}

		// Not visible yet: it will be new when it shows.
		if ($visible > $now) {
			return true;
		}

		return $visible >= $now->modify('-' . self::NEW_FOR_DAYS . ' days');
	}//end isNew()

	/**
	 * "4 medewerkers" and "via eHerkenning" for a company: its active
	 * employees in the institute's register, and its way in.
	 *
	 * @param array<string, mixed> $row The company.
	 *
	 * @return array{employeeCountLabel: string|null, signInLabel: string|null}
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function companyLines(array $row): array {
		$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		$count = null;
		if ($id !== '') {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => 'learner-profile', 'organisationRef' => $id],
					'limit' => 1000,
				],
				_rbac: false,
				_multitenancy: false
			);
			$count = 0;
			foreach ($rows as $profile) {
				$data = $this->toRow(object: $profile);
				if (($data['organisationRef'] ?? null) === $id && ($data['lifecycle'] ?? 'active') === 'active') {
					$count++;
				}
			}
		}

		$signIn = null;
		if (trim((string)($row['eherkenningRef'] ?? '')) !== '') {
			$signIn = 'via eHerkenning';
		}

		return ['employeeCountLabel' => self::employees(count: $count), 'signInLabel' => $signIn];
	}//end companyLines()

	/**
	 * "1 medewerker", "4 medewerkers", or null when unknown.
	 *
	 * @param int|null $count The count.
	 *
	 * @return string|null
	 */
	public static function employees(?int $count): ?string {
		if ($count === null) {
			return null;
		}

		if ($count === 1) {
			return '1 medewerker';
		}

		return $count . ' medewerkers';
	}//end employees()

	/**
	 * One text field of the object a pointer names, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param mixed  $id     The pointer.
	 * @param string $field  The field.
	 *
	 * @return string|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function field(string $schema, mixed $id, string $field): ?string {
		if (is_string($id) === false || $id === '') {
			return null;
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($rows as $row) {
			$data = $this->toRow(object: $row);
			if (($data['id'] ?? ($data['uuid'] ?? null)) === $id) {
				$value = trim((string)($data[$field] ?? ''));
				if ($value === '') {
					return null;
				}

				return $value;
			}
		}

		return null;
	}//end field()

	/**
	 * A teacher's display name, or null.
	 *
	 * @param mixed $uid The user id.
	 *
	 * @return string|null
	 */
	private function userName(mixed $uid): ?string {
		if (is_string($uid) === false || $uid === '') {
			return null;
		}

		$name = trim((string)$this->users->getDisplayName($uid));
		if ($name === '' || $name === $uid) {
			return null;
		}

		return $name;
	}//end userName()

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
}//end class
