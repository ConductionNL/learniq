<?php

/**
 * Learniq xAPI Statement Comparator
 *
 * Decides whether two xAPI statements match, per xAPI 1.0.3 Data 2.3.1
 * (Statement Immutability) and its Statement Comparison Requirements. An LRS
 * that receives a statement whose id it already stores answers success when
 * the two match and 409 Conflict when they do not, and it needs this rule to
 * tell the two apart.
 *
 * Differences that the specification allows, and that are therefore ignored:
 * - properties the LRS assigns or may assign: `id`, `authority`, `stored`,
 *   `version` (and learniq's own stamped fields, which are not statement
 *   properties at all);
 * - the Activity Definition of an Activity, which is not part of the
 *   statement itself;
 * - the serialisation of a timestamp (compared as an instant);
 * - upper or lower case in a UUID;
 * - the order of the members of a Group;
 * - the order of the members of a JSON object, and a member that is null
 *   against one that is absent.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#9-statement-id-conflicts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Compares two xAPI statements under the specification's equivalence rules.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiStatementComparator {

	/**
	 * The statement properties that take part in the comparison.
	 *
	 * @var array<int, string>
	 */
	private const COMPARED = ['actor', 'verb', 'object', 'result', 'context', 'timestamp'];

	/**
	 * A UUID, any version.
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Whether two statements match.
	 *
	 * @param array<string, mixed> $incoming The statement as sent.
	 * @param array<string, mixed> $stored   The statement as stored.
	 *
	 * @return bool True when they are equivalent.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#9-statement-id-conflicts
	 */
	public function equivalent(array $incoming, array $stored): bool {
		return $this->canonical(statement: $incoming) === $this->canonical(statement: $stored);
	}//end equivalent()

	/**
	 * The canonical JSON of the compared part of a statement.
	 *
	 * @param array<string, mixed> $statement The statement.
	 *
	 * @return string The canonical form.
	 */
	private function canonical(array $statement): string {
		$compared = array_intersect_key($statement, array_flip(self::COMPARED));
		if (isset($compared['timestamp']) === true) {
			$compared['timestamp'] = $this->instant(value: $compared['timestamp']);
		}

		if (is_array($compared['object'] ?? null) === true) {
			$compared['object'] = $this->withoutDefinition(object: $compared['object']);
		}

		if (is_array($compared['context']['contextActivities'] ?? null) === true) {
			$compared['context']['contextActivities'] = $this->contextActivities(activities: $compared['context']['contextActivities']);
		}

		return (string)json_encode($this->normalise(value: $compared), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end canonical()

	/**
	 * An object without its Activity Definition, which is not part of the statement.
	 *
	 * @param array<string, mixed> $object The statement object or a context activity.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function withoutDefinition(array $object): array {
		if (($object['objectType'] ?? 'Activity') === 'Activity') {
			unset($object['definition']);
		}

		return $object;
	}//end withoutDefinition()

	/**
	 * Context activities without their definitions.
	 *
	 * @param array<string, mixed> $activities `parent`, `grouping`, `category`, `other`.
	 *
	 * @return array<string, mixed> The same lists, definitions removed.
	 */
	private function contextActivities(array $activities): array {
		foreach ($activities as $kind => $list) {
			if (is_array($list) === false) {
				continue;
			}

			// A single activity object is allowed where a list is expected.
			if (isset($list['id']) === true) {
				$list = [$list];
			}

			foreach ($list as $index => $activity) {
				if (is_array($activity) === true) {
					$list[$index] = $this->withoutDefinition(object: $activity);
				}
			}

			$activities[$kind] = $list;
		}

		return $activities;
	}//end contextActivities()

	/**
	 * Sort object keys, sort Group members, and lowercase UUIDs, recursively.
	 *
	 * @param mixed $value Any JSON value.
	 *
	 * @return mixed The normalised value.
	 */
	private function normalise(mixed $value): mixed {
		if (is_string($value) === true && preg_match(self::UUID, $value) === 1) {
			return strtolower($value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		$value = array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		if (array_is_list($value) === false) {
			// A property sent as null and a property not sent say the same thing,
			// and a store may hand back an absent property as null.
			$value = array_filter($value, static fn (mixed $item): bool => $item !== null);
			ksort($value);
			if (is_array($value['member'] ?? null) === true) {
				$value['member'] = $this->sortedList(list: $value['member']);
			}
		}

		return $value;
	}//end normalise()

	/**
	 * A list sorted by the canonical JSON of its items.
	 *
	 * @param array<int|string, mixed> $list The list.
	 *
	 * @return array<int, mixed> The sorted list.
	 */
	private function sortedList(array $list): array {
		$list = array_values($list);
		usort($list, static fn (mixed $left, mixed $right): int => strcmp((string)json_encode($left), (string)json_encode($right)));
		return $list;
	}//end sortedList()

	/**
	 * A timestamp as an instant with microseconds, so serialisation differences do not count.
	 *
	 * @param mixed $value The timestamp.
	 *
	 * @return mixed The instant as `U.u`, or the value as sent when it does not parse.
	 */
	private function instant(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		try {
			return (new DateTimeImmutable($value))->format('U.u');
		} catch (Throwable $e) {
			return $value;
		}
	}//end instant()
}//end class
