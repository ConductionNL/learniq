<?php

/**
 * Learniq parent portal teacher names tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/parent-portal-teacher-names/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every parent column that holds a teacher's Nextcloud user id asks portaliq
 * for the display name (`render: user`, portaliq#1077), and the parent
 * section is titled "School", not the app's name.
 *
 * @spec openspec/changes/parent-portal-teacher-names/specs/portal-contribution/spec.md
 */
class ParentTeacherNamesTest extends TestCase {

	/**
	 * The parent columns that show a staff user id, by collection.
	 */
	private const USER_COLUMNS = [
		'parentConferenceFreeSlots' => 'teacherId',
		'parentConferenceSlots' => 'teacherId',
		'parentConferenceSignups' => 'requestedTeacherIds',
		'parentExcuseRequests' => 'decidedBy',
	];

	/**
	 * The parent manifest.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		return (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
	}//end manifest()

	/**
	 * Each teacher column renders as a name, and its field is projected so
	 * portaliq reads it; no other column is marked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-portal-teacher-names/specs/portal-contribution/spec.md#requirement-a-guardian-reads-a-teacher-by-name
	 */
	public function testTeacherColumnsRenderAsNames(): void {
		$marked = [];
		foreach ($this->manifest()['collections'] as $collection) {
			foreach (($collection['columns'] ?? []) as $column) {
				if (($column['render'] ?? '') === 'user') {
					$marked[$collection['id']] = $column['field'];
					self::assertContains($column['field'], $collection['fields'], $collection['id']);
				}
			}
		}

		ksort($marked);
		$expected = self::USER_COLUMNS;
		ksort($expected);
		self::assertSame($expected, $marked);
	}//end testTeacherColumnsRenderAsNames()

	/**
	 * A staff user id is projected only where a name column reads it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-portal-teacher-names/specs/portal-contribution/spec.md#requirement-a-guardian-reads-a-teacher-by-name
	 */
	public function testNoUserIdLeavesWithoutANameColumn(): void {
		foreach ($this->manifest()['collections'] as $collection) {
			$named = array_column(array_filter(($collection['columns'] ?? []), static fn (array $c): bool => ($c['render'] ?? '') === 'user'), 'field');
			foreach (['teacherId', 'teacherIds', 'requestedTeacherIds', 'decidedBy', 'markedBy', 'grader'] as $field) {
				if (in_array($field, ($collection['fields'] ?? []), true) === true) {
					self::assertContains($field, $named, $collection['id'].' projects '.$field.' without a name column');
				}
			}
		}
	}//end testNoUserIdLeavesWithoutANameColumn()

	/**
	 * The parent section is called "School" (in Dutch too), not "Learniq".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-portal-teacher-names/specs/portal-contribution/spec.md#requirement-the-parent-section-is-called-school
	 */
	public function testTheParentSectionIsCalledSchool(): void {
		self::assertSame('School', $this->manifest()['label']);
		self::assertSame('Learniq', (new PortalContributionProvider())->getContribution(['audience' => 'student'])['label']);
	}//end testTheParentSectionIsCalledSchool()
}//end class
