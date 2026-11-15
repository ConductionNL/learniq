<?php

/**
 * Learniq self-match field test.
 *
 * A read rule `{"match": {"<field>": "$userId"}}`, an `x-property-rbac` match
 * on `$userId`, and a notification sent to `{"kind": "field"}` all compare the
 * field with a Nextcloud user id. When that field is declared as a
 * LearnerProfile uuid (format uuid or $ref LearnerProfile) it never equals a
 * user id, so the learner is refused their own rows and the notification
 * reaches nobody. Credential (learniq#1457) and ExternalTrainingRecord had
 * exactly this; both now match on `learnerUserId`, which holds the user id.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-schemas-declared-audience-is-enforced-by-its-authorization-block
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use OCA\Learniq\Listener\LearnerUserIdStamp;
use PHPUnit\Framework\TestCase;

/**
 * No user-id comparison is made against a LearnerProfile uuid property.
 */
class SelfMatchNamesAUserIdTest extends TestCase {

	/**
	 * Known violations not yet fixed, each by the place it sits. Empty: every
	 * schema that names its learner by profile uuid now matches on a user-id
	 * field. The ratchet below still fails when an entry here is fixed, so a
	 * future entry is removed with its fix.
	 *
	 * @var list<string>
	 */
	private const PENDING = [];

	/**
	 * Every user-id comparison against a LearnerProfile uuid property in the
	 * shipped register.
	 *
	 * @return list<string>
	 */
	private static function violations(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$found = [];
		foreach (($register['components']['schemas'] ?? []) as $name => $schema) {
			$uuidFields = self::profileUuidFields(properties: (array)($schema['properties'] ?? []));
			if ($uuidFields === []) {
				continue;
			}

			foreach ((array)($schema['authorization'] ?? []) as $action => $rules) {
				foreach ((array)$rules as $rule) {
					foreach ((array)(is_array($rule) === true ? ($rule['match'] ?? []) : []) as $field => $value) {
						if ($value === '$userId' && in_array($field, $uuidFields, true) === true) {
							$found[] = $name . ' authorization.' . $action . ' ' . $field;
						}
					}
				}
			}

			foreach ((array)($schema['x-property-rbac'] ?? []) as $action => $rule) {
				if (is_array($rule) === false) {
					continue;
				}

				foreach ((array)($rule['anyOf'] ?? []) as $option) {
					$match = (array)($option['match'] ?? []);
					if (($match['value'] ?? null) === '$userId' && in_array(($match['field'] ?? null), $uuidFields, true) === true) {
						$found[] = $name . ' x-property-rbac.' . $action . ' ' . $match['field'];
					}
				}
			}

			foreach ((array)($schema['x-openregister-notifications'] ?? []) as $key => $notification) {
				foreach ((array)($notification['recipients'] ?? []) as $recipient) {
					if (($recipient['kind'] ?? null) === 'field' && in_array(($recipient['field'] ?? null), $uuidFields, true) === true) {
						$found[] = $name . ' notification ' . $key . ' ' . $recipient['field'];
					}
				}
			}
		}//end foreach

		return $found;
	}//end violations()

	/**
	 * The properties declared as a LearnerProfile uuid.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return list<string>
	 */
	private static function profileUuidFields(array $properties): array {
		$fields = [];
		foreach ($properties as $field => $property) {
			if (is_array($property) === false) {
				continue;
			}

			if (($property['$ref'] ?? null) === 'LearnerProfile' || ($property['format'] ?? null) === 'uuid') {
				$fields[] = (string)$field;
			}
		}

		return $fields;
	}//end profileUuidFields()

	/**
	 * No read rule, property RBAC or notification compares a user id with a
	 * LearnerProfile uuid, outside the named pending list. Red on development
	 * for ExternalTrainingRecord, ExemptionCase and FraudCase.
	 *
	 * @return void
	 */
	public function testNoUserIdIsComparedWithAProfileUuid(): void {
		self::assertSame([], array_values(array_diff(self::violations(), self::PENDING)));
	}//end testNoUserIdIsComparedWithAProfileUuid()

	/**
	 * The learner of an ExternalTrainingRecord or ExemptionCase, and the
	 * accused learner of a FraudCase, read their own row through the user-id
	 * field the server stamps (LearnerUserIdStamp::FIELDS), a declared
	 * property that is not a uuid.
	 *
	 * @return void
	 */
	public function testTheLearnerReadsTheirOwnRowThroughTheStampedUserId(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schemas = ['ExternalTrainingRecord' => 'learnerUserId', 'ExemptionCase' => 'learnerUserId', 'FraudCase' => 'accusedLearnerUserId'];
		foreach ($schemas as $name => $field) {
			$schema = $register['components']['schemas'][$name];

			self::assertArrayHasKey($field, $schema['properties'], $name);
			self::assertArrayNotHasKey('format', $schema['properties'][$field], $name);
			self::assertContains(['group' => 'authenticated', 'match' => [$field => '$userId']], $schema['authorization']['read'], $name);
			self::assertSame($field, (LearnerUserIdStamp::FIELDS[(string)($schema['slug'] ?? '')][1] ?? null), $name . ': the stamp writes the field the rule reads.');
		}
	}//end testTheLearnerReadsTheirOwnRowThroughTheStampedUserId()

	/**
	 * Every pending entry is still a violation: fixing one fails here until
	 * it is taken off the list.
	 *
	 * @return void
	 */
	public function testThePendingListOnlyNamesLiveViolations(): void {
		self::assertSame([], array_values(array_diff(self::PENDING, self::violations())));
	}//end testThePendingListOnlyNamesLiveViolations()
}//end class
