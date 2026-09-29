<?php

/**
 * Learniq Catalogue Reader
 *
 * The learner course catalogue (enrolment-catalogue-self-signup): every
 * published course and programme whose `selfEnrolment` is `open` or
 * `on-request`, with search on name, description and tags and filters on
 * level, language, subject and provider (`author`), and for each entry the
 * learner's own enrolment, if any, so a screen can offer Sign up, Request a
 * place or Withdraw. Read as the system: the same answer serves the app and
 * the portal, whose forward has no Nextcloud session. Only catalogue fields
 * leave this class.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Catalogue
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Catalogue;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Lists what a learner may sign up for.
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
class CatalogueReader {

	private const REGISTER = 'learniq';

	/**
	 * The `selfEnrolment` values that put an entry in the catalogue.
	 */
	public const OPEN_VALUES = ['open', 'on-request'];

	/**
	 * Enrolment states that count as "signed up".
	 */
	public const LIVE_STATES = ['pending', 'active'];

	/**
	 * The fields a filter may name.
	 */
	private const FILTERS = ['level', 'language', 'subject', 'author'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
	) {
	}//end __construct()

	/**
	 * The catalogue for one learner.
	 *
	 * @param string                $userId  The learner's Nextcloud user id.
	 * @param string                $search  Free text, '' for all.
	 * @param array<string, string> $filters Field => value, among level, language, subject, author.
	 *
	 * @return array{courses: list<array<string, mixed>>, programmes: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-provider-courses-show-their-provider
	 */
	public function entries(string $userId, string $search = '', array $filters = []): array {
		$mine = $this->enrolmentsByCourse(userId: $userId);
		$courses = [];
		foreach ($this->published(schema: 'course') as $course) {
			if ($this->matches(row: $course, search: $search, filters: $filters) === true) {
				$courses[] = $this->card(row: $course, kind: 'course') + ['enrolment' => ($mine[(string)$course['id']] ?? null)];
			}
		}

		$programmes = [];
		foreach ($this->published(schema: 'programme') as $programme) {
			if ($this->matches(row: $programme, search: $search, filters: $filters) === true) {
				$programmes[] = $this->card(row: $programme, kind: 'programme') + ['courseIds' => array_values((array)($programme['courseIds'] ?? []))];
			}
		}

		return ['courses' => $courses, 'programmes' => $programmes];
	}//end entries()

	/**
	 * The learner's live and past enrolments, keyed by course id; a live one
	 * wins over a withdrawn or finished one.
	 *
	 * @param string $userId The learner.
	 *
	 * @return array<string, array{id: string, lifecycle: string, source: string, progressPercent: float}>
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
	 */
	public function enrolmentsByCourse(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$rows = $this->objects->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'enrolment', 'learnerId' => $userId], 'limit' => 1000],
			_rbac: false
		);

		$byCourse = [];
		foreach ($rows as $row) {
			$row = $this->toArray(value: $row);
			$courseId = (string)($row['courseId'] ?? '');
			$lifecycle = (string)($row['lifecycle'] ?? '');
			$known = ($byCourse[$courseId] ?? null);
			if ($known !== null && in_array($known['lifecycle'], self::LIVE_STATES, true) === true) {
				continue;
			}

			$byCourse[$courseId] = [
				'id' => (string)$row['id'],
				'lifecycle' => $lifecycle,
				'source' => (string)($row['source'] ?? ''),
				'progressPercent' => (float)($row['progressPercent'] ?? 0),
			];
		}

		return $byCourse;
	}//end enrolmentsByCourse()

	/**
	 * Published rows of a schema that are open for sign-up.
	 *
	 * @param string $schema `course` or `programme`.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function published(string $schema): array {
		$rows = $this->objects->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema, 'lifecycle' => 'published'], 'limit' => 2000],
			_rbac: false
		);

		$open = [];
		foreach ($rows as $row) {
			$row = $this->toArray(value: $row);
			if (in_array(($row['selfEnrolment'] ?? 'closed'), self::OPEN_VALUES, true) === true) {
				$open[] = $row;
			}
		}

		return $open;
	}//end published()

	/**
	 * Whether a row passes the search and every filter.
	 *
	 * @param array<string, mixed>  $row     The course or programme.
	 * @param string                $search  Free text.
	 * @param array<string, string> $filters The filters.
	 *
	 * @return bool
	 */
	private function matches(array $row, string $search, array $filters): bool {
		foreach (self::FILTERS as $field) {
			$wanted = trim((string)($filters[$field] ?? ''));
			if ($wanted !== '' && strcasecmp((string)($row[$field] ?? ''), $wanted) !== 0) {
				return false;
			}
		}

		$search = mb_strtolower(trim($search));
		if ($search === '') {
			return true;
		}

		$haystack = mb_strtolower(
			implode(' ', [(string)($row['name'] ?? ''), (string)($row['description'] ?? ''), implode(' ', array_map('strval', (array)($row['tags'] ?? [])))])
		);

		return str_contains($haystack, $search);
	}//end matches()

	/**
	 * The catalogue fields of a row.
	 *
	 * @param array<string, mixed> $row  The course or programme.
	 * @param string               $kind `course` or `programme`.
	 *
	 * @return array<string, mixed>
	 */
	private function card(array $row, string $kind): array {
		return [
			'id' => (string)$row['id'],
			'kind' => $kind,
			'name' => (string)($row['name'] ?? ''),
			'description' => (string)($row['description'] ?? ''),
			'level' => $row['level'] ?? null,
			'language' => $row['language'] ?? null,
			'subject' => $row['subject'] ?? null,
			'provider' => $row['author'] ?? null,
			'ectsCredits' => $row['ectsCredits'] ?? null,
			'selfEnrolment' => (string)$row['selfEnrolment'],
		];
	}//end card()

	/**
	 * An OpenRegister result as an array with a string `id`.
	 *
	 * @param mixed $value An array or an entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return ['id' => ''];
		}

		$value['id'] = (string)($value['id'] ?? ($value['@self']['id'] ?? ''));

		return $value;
	}//end toArray()
}//end class
