<?php

/**
 * Learniq import record access-rule test.
 *
 * `LvsResult` and `OsoImportDossier` declared their audience only in
 * `x-property-rbac`, which OpenRegister does not read, so both fell through to
 * the register cascade: four staff groups read and wrote every row, the
 * coordinators the verify, accept and reject guards name could not find one,
 * and a pupil could not read their own normed results. This test pins the
 * enforced blocks of decision D23 exactly, and checks that the only groups
 * gaining read are the guards' own actors.
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
 * @spec openspec/changes/access-control-ratchet-compliance/specs/data-exchange/spec.md#requirement-imported-lvs-results-and-transfer-dossiers-are-read-and-written-by-the-groups-that-review-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Asserts the enforced access of the two data-exchange import records.
 */
class ImportRecordAccessTest extends TestCase {

	/**
	 * The groups that review imports and write the rows.
	 */
	private const REVIEWERS = ['coordinators', 'compliance-officers'];

	/**
	 * The groups the register cascade granted read and write to.
	 */
	private const CASCADE = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

	/**
	 * The two import records.
	 */
	private const IMPORT_RECORDS = ['LvsResult', 'OsoImportDossier'];

	/**
	 * The shipped schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * An imported LVS result is read by the reviewing groups and by the pupil
	 * it is about, and by nobody else (admins pass OpenRegister's bypass).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-control-ratchet-compliance/specs/data-exchange/spec.md#scenario-a-pupil-reads-their-own-lvs-result-and-not-a-classmates
	 */
	public function testLvsResultIsReadByTheReviewersAndThePupil(): void {
		$this->assertSame(
			expected: [...self::REVIEWERS, ['group' => 'authenticated', 'match' => ['learnerId' => '$userId']]],
			actual: $this->schemas()['LvsResult']['authorization']['read']
		);
	}//end testLvsResultIsReadByTheReviewersAndThePupil()

	/**
	 * A transfer dossier is read by the reviewing groups only. Its
	 * `learnerEckId` is not a Nextcloud user, so there is no self-read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-control-ratchet-compliance/specs/data-exchange/spec.md#scenario-an-instructor-no-longer-reads-transfer-dossiers
	 */
	public function testOsoImportDossierIsReadByTheReviewersOnly(): void {
		$this->assertSame(expected: self::REVIEWERS, actual: $this->schemas()['OsoImportDossier']['authorization']['read']);
	}//end testOsoImportDossierIsReadByTheReviewersOnly()

	/**
	 * Both records are created and updated by the reviewing groups, and only
	 * an admin deletes.
	 *
	 * @return void
	 */
	public function testImportRecordsAreWrittenByTheReviewersOnly(): void {
		$schemas = $this->schemas();
		foreach (self::IMPORT_RECORDS as $name) {
			$authorization = $schemas[$name]['authorization'];
			$this->assertSame(expected: self::REVIEWERS, actual: $authorization['create'], message: $name . ' create');
			$this->assertSame(expected: self::REVIEWERS, actual: $authorization['update'], message: $name . ' update');
			$this->assertArrayNotHasKey(key: 'delete', array: $authorization, message: $name . ' delete');
		}
	}//end testImportRecordsAreWrittenByTheReviewersOnly()

	/**
	 * Every group a transition guard of these schemas authorises (admin
	 * aside) can read and update the row, or the guard could never be reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-control-ratchet-compliance/specs/nextcloud-app/spec.md#scenario-a-coordinator-finds-the-transfer-dossier-they-must-review
	 */
	public function testEveryGuardGroupReadsAndUpdatesTheRow(): void {
		$schemas = $this->schemas();
		$checked = 0;
		foreach (self::IMPORT_RECORDS as $name) {
			$authorization = $schemas[$name]['authorization'];
			foreach ($schemas[$name]['x-openregister-lifecycle']['transitions'] as $transition => $rule) {
				if (isset($rule['requires']) === false) {
					continue;
				}

				$groups = (new ReflectionClass($rule['requires']))->getConstant('AUTHORISED_GROUPS');
				foreach (array_diff($groups, ['admin']) as $group) {
					$this->assertContains(needle: $group, haystack: $authorization['read'], message: $name . '.' . $transition . ' read');
					$this->assertContains(needle: $group, haystack: $authorization['update'], message: $name . '.' . $transition . ' update');
					$checked++;
				}
			}
		}

		// Verify, accept and reject: an empty scan must not pass.
		$this->assertSame(expected: 3, actual: $checked);
	}//end testEveryGuardGroupReadsAndUpdatesTheRow()

	/**
	 * Against the cascade the blocks replace, a group gains read only when a
	 * guard of the schema names it as its actor; every other named group
	 * already read the row.
	 *
	 * @return void
	 */
	public function testNoGroupGainsReadExceptTheGuardsActor(): void {
		$schemas = $this->schemas();
		foreach (self::IMPORT_RECORDS as $name) {
			$actors = [];
			foreach ($schemas[$name]['x-openregister-lifecycle']['transitions'] as $rule) {
				if (isset($rule['requires']) === true) {
					$actors = [...$actors, ...(new ReflectionClass($rule['requires']))->getConstant('AUTHORISED_GROUPS')];
				}
			}

			$groups = array_filter($schemas[$name]['authorization']['read'], 'is_string');
			$this->assertSame(expected: [], actual: array_values(array_diff($groups, self::CASCADE, $actors)), message: $name);
		}
	}//end testNoGroupGainsReadExceptTheGuardsActor()
}//end class
