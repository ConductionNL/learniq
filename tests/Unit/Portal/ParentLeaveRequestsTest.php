<?php

/**
 * Learniq parent leave requests tests.
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ParentLeaveRequests;
use OCA\Learniq\Portal\ParentPortalCollections;
use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalValueLabels;
use PHPUnit\Framework\TestCase;

/**
 * A guardian asks leave for her own child, reads the decision and sees what still waits.
 *
 * @covers \OCA\Learniq\Portal\ParentLeaveRequests
 */
class ParentLeaveRequestsTest extends TestCase {

	/**
	 * The parent manifest as portaliq receives it.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		return (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
	}//end manifest()

	/**
	 * The guardian's reverse join to her children, as every parent read uses it.
	 *
	 * @return array<string, mixed>
	 */
	private static function childJoin(): array {
		$byId = array_column(self::manifest()['collections'], null, 'id');

		return $byId['parentExcuseRequests']['via'];
	}//end childJoin()

	/**
	 * Who asked is stamped from her session, the child must be one of hers, and the form never carries who asked.
	 *
	 * @return void
	 */
	public function testTheActionStampsTheGuardianAndChecksTheChild(): void {
		$actions = array_column(self::manifest()['actions'], null, 'id');
		$action  = $actions[ParentLeaveRequests::ACTION];

		self::assertSame(['create', 'learniq', 'leave-request'], [$action['type'], $action['register'], $action['schema']]);
		self::assertSame(['requestedByRef', 'guardianRef', 'substantial'], [$action['scopeField'], $action['scopeClaim'], $action['minTrust']]);
		self::assertSame(self::childJoin(), $action['via']);
		self::assertNotContains('requestedByRef', $action['fields']);
		self::assertSame(['learnerRef', 'dateFrom', 'dateTo', 'kind', 'reason', 'attachmentRef'], $action['fields']);

		// "Not her child": portaliq refuses a learnerRef outside her children before it writes.
		$children = new ParentPortalCollections();
		self::assertSame(['learnerRef' => $children->childCrossRef()], $action['crossRefs']);
		self::assertTrue($action['crossRefs']['learnerRef']['required']);
		self::assertSame(['guardianRefs', 'guardianRef'], [$action['crossRefs']['learnerRef']['scopeField'], $action['crossRefs']['learnerRef']['scopeClaim']]);
		self::assertSame(['learnerRef' => $children->childOptions()], $action['optionsProviders']);

		// Every field the form asks is a field of the schema, and the required ones are asked.
		self::assertSame($action['fields'], array_keys($action['fieldConfigs']));
		foreach (['learnerRef', 'dateFrom', 'dateTo', 'kind', 'reason'] as $field) {
			self::assertTrue($action['fieldConfigs'][$field]['required'], $field);
		}

		self::assertSame(PortalValueLabels::LEAVE_KIND, $action['fieldConfigs']['kind']['valueLabels']);
	}//end testTheActionStampsTheGuardianAndChecksTheChild()

	/**
	 * Her list carries the days, the state in words and the decision note, never who decided as a user id.
	 *
	 * @return void
	 */
	public function testHerListShowsTheStateInWordsAndTheDecisionNote(): void {
		[$own, $open] = (new ParentLeaveRequests())->collections(childJoin: self::childJoin());

		self::assertSame([ParentLeaveRequests::COLLECTION, true], [$own['id'], $own['listable']]);
		self::assertSame(['learnerRef', 'guardianRef', 'substantial'], [$own['scopeField'], $own['scopeClaim'], $own['minTrust']]);
		self::assertSame(self::childJoin(), $own['via']);
		foreach (['dateFrom', 'dateTo', 'lifecycle', 'decideBy', 'decisionNote'] as $field) {
			self::assertContains($field, $own['fields'], $field);
		}

		foreach (['decidedBy', 'requestedByRef', 'teacherIds'] as $field) {
			self::assertNotContains($field, $own['fields'], $field);
		}

		$columns = array_column($own['columns'], null, 'field');
		self::assertSame(PortalValueLabels::LEAVE_STATUS, $columns['lifecycle']['valueLabels']);
		self::assertSame('Submitted', $columns['lifecycle']['valueLabels']['submitted']);
		foreach (array_keys($columns) as $field) {
			self::assertContains($field, $own['fields'], 'a column reads a projected field: ' . $field);
		}

		// The overview's collection is her own rows, narrowed to those still open.
		self::assertSame([ParentLeaveRequests::OPEN_COLLECTION, false], [$open['id'], $open['listable']]);
		self::assertSame(['lifecycle' => 'submitted'], $open['filter']);
		self::assertSame($own['via'], $open['via']);
		self::assertSame($own['fields'], $open['fields']);
		self::assertArrayNotHasKey('filter', $own);
	}//end testHerListShowsTheStateInWordsAndTheDecisionNote()

	/**
	 * The page holds the form and her list; the overview names an open decision; both read what the manifest declares.
	 *
	 * @return void
	 */
	public function testThePageAndTheOverviewReadDeclaredCollections(): void {
		$manifest    = self::manifest();
		$pages       = array_column($manifest['pages'], null, 'id');
		$collections = array_column($manifest['collections'], null, 'id');
		$leave       = new ParentLeaveRequests();

		self::assertSame($leave->page(), $pages[ParentLeaveRequests::PAGE]);
		self::assertSame(['type' => 'action', 'action' => ParentLeaveRequests::ACTION], $pages[ParentLeaveRequests::PAGE]['blocks'][0]);
		$list = $pages[ParentLeaveRequests::PAGE]['blocks'][1];
		self::assertSame(ParentLeaveRequests::COLLECTION, $list['collection']);
		self::assertSame(['decisionNote', 'decidedAt'], [$list['statusNoteField'], $list['statusNoteAtField']]);
		self::assertSame(array_keys(PortalValueLabels::LEAVE_STATUS), array_keys($list['statusTones']));

		self::assertContains($leave->overviewBlock(), $pages['parentOverview']['blocks']);
		$overview = $leave->overviewBlock();
		self::assertSame(ParentLeaveRequests::OPEN_COLLECTION, $overview['collection']);
		self::assertSame('decideBy', $overview['dateField']);

		foreach ([$list, $overview] as $block) {
			$fields = $collections[$block['collection']]['fields'];
			foreach (array_merge([$block['dateField'], $block['statusField']], array_diff($block['titleFields'], ['childName'])) as $field) {
				self::assertContains($field, $fields, $block['collection'] . ': ' . $field);
			}
		}
	}//end testThePageAndTheOverviewReadDeclaredCollections()
}//end class
