<?php

/**
 * Unit tests for reportRecovered and the seen line on the guardian's absence page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExcuseRecoveryAction;
use OCA\Learniq\Portal\ParentSitePages;
use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guardian reads that the school saw her report and ends an open
 * illness; a pupil who reports for herself does the same.
 *
 * @covers \OCA\Learniq\Portal\ExcuseRecoveryAction
 * @covers \OCA\Learniq\Portal\ParentSitePages
 * @covers \OCA\Learniq\Portal\PortalContributionProvider
 */
class ExcuseRecoveryActionTest extends TestCase {

	/**
	 * One audience's manifest.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(string $audience): array {
		return (new PortalContributionProvider())->getContribution(['audience' => $audience]);
	}//end manifest()

	/**
	 * The shipped ExcuseRequest schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas']['ExcuseRequest'];
	}//end schema()

	/**
	 * The guardian ends her child's illness through the same join her reports are read through.
	 *
	 * @return void
	 */
	public function testTheGuardianEndsAnIllnessOfHerOwnChild(): void {
		$manifest = self::manifest(audience: 'parent');
		$actions = array_column($manifest['actions'], null, 'id');
		$collections = array_column($manifest['collections'], null, 'id');
		$action = $actions['reportRecovered'];

		self::assertSame(['update', 'excuse-request', 'learniq'], [$action['type'], $action['schema'], $action['register']]);
		self::assertSame(['learnerRef', 'guardianRef', 'substantial'], [$action['scopeField'], $action['scopeClaim'], $action['minTrust']]);
		self::assertSame($collections['parentExcuseRequests']['via'], $action['via']);
		self::assertSame(['dateTo'], $action['fields']);
		self::assertSame(['dateTo'], $action['requiredFields']);
		self::assertSame('Last day your child was ill', $action['fieldConfigs']['dateTo']['label']);
		self::assertSame(['field' => 'lifecycle', 'in' => ['submitted', 'approved']], $action['rowWhen']);
		self::assertSame(['reportRecovered'], $collections['parentExcuseRequests']['rowActions']);
	}//end testTheGuardianEndsAnIllnessOfHerOwnChild()

	/**
	 * A pupil of eighteen reports herself ill without a last day, with lesson hours, and ends it herself.
	 *
	 * @return void
	 */
	public function testAPupilReportsAndEndsHerOwnIllness(): void {
		$manifest = self::manifest(audience: 'student');
		$actions = array_column($manifest['actions'], null, 'id');
		$collections = array_column($manifest['collections'], null, 'id');

		$recover = $actions['reportRecovered'];
		self::assertSame(['learnerRef', 'learnerRef', 'low'], [$recover['scopeField'], $recover['scopeClaim'], $recover['minTrust']]);
		self::assertArrayNotHasKey('via', $recover);
		self::assertSame('Last day you were ill', $recover['fieldConfigs']['dateTo']['label']);
		self::assertSame(['reportRecovered'], $collections['studentExcuseRequests']['rowActions']);

		$report = $actions['createExcuseRequest'];
		self::assertContains('fromLessonHour', $report['fields']);
		self::assertContains('toLessonHour', $report['fields']);
		self::assertFalse($report['fieldConfigs']['dateTo']['required']);
	}//end testAPupilReportsAndEndsHerOwnIllness()

	/**
	 * While it is submitted, the absence page reads who saw the report and when.
	 *
	 * @return void
	 */
	public function testTheAbsencePageReadsWhoSawTheReport(): void {
		$rows = (new ParentSitePages())->absencePage()['blocks'][1];

		self::assertSame(
			['submitted' => ['field' => 'seenBy', 'atField' => 'seenAt', 'render' => 'user', 'template' => 'Seen by {name} at {time}']],
			$rows['statusNotes']
		);
		// Once decided, the decision line as before.
		self::assertSame(['decidedBy', 'decidedAt'], [$rows['statusNoteField'], $rows['statusNoteAtField']]);
		self::assertSame(['reportRecovered'], $rows['rowActions']);

		$collections = array_column(self::manifest(audience: 'parent')['collections'], null, 'id');
		self::assertContains('seenBy', $collections['parentExcuseRequests']['fields']);
		self::assertContains('seenAt', $collections['parentExcuseRequests']['fields']);
	}//end testTheAbsencePageReadsWhoSawTheReport()

	/**
	 * Every field the portal reads or writes for this is a property of the shipped schema.
	 *
	 * @return void
	 */
	public function testTheFieldsAreTheSchemasOwn(): void {
		$properties = array_keys(self::schema()['properties']);

		foreach (['seenBy', 'seenAt', 'fromLessonHour', 'toLessonHour', 'dateTo'] as $field) {
			self::assertContains($field, $properties);
		}

		// An open illness is the one report the register lets go without a last day.
		self::assertNotContains('dateTo', self::schema()['required']);
	}//end testTheFieldsAreTheSchemasOwn()

	/**
	 * The guardian reads the seen line in Dutch.
	 *
	 * @return void
	 */
	public function testTheSeenLineIsDutch(): void {
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];

		self::assertSame('Gezien door {name} om {time} uur', $nl['Seen by {name} at {time}']);
		self::assertSame('Beter melden', $nl['Report better']);
		self::assertSame('reportRecovered', ExcuseRecoveryAction::ID);
	}//end testTheSeenLineIsDutch()
}//end class
