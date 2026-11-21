<?php

/**
 * The guardian's task per child and the student's self-assessment
 * (guardian-tasks-per-child-and-self-assessment).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\StudentSelfAssessment;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * Declarations of the guardian's per-child task and the student's own estimate.
 */
class GuardianTasksAndSelfAssessmentTest extends TestCase {

	use RegisterSchemaPayloads;

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
	 * The guardian's task reads one open invitation per child, only her own
	 * children (the same child join as every parent read), titled with the
	 * child's name; every place in the title is a projected field or a lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-guardian-reads-one-task-per-child-with-the-childs-name
	 */
	public function testTheGuardianTaskIsOnePerChildAndNamesTheChild(): void {
		$manifest = self::manifest(audience: 'parent');
		$collections = array_column($manifest['collections'], null, 'id');
		$invitations = $collections['parentConferenceInvitations'];

		self::assertSame(['conference-invitation', 'learnerRef', 'guardianRef'], [$invitations['schema'], $invitations['scopeField'], $invitations['scopeClaim']]);
		self::assertSame($collections['parentGroupMemberships']['via'], $invitations['via'], 'the child join every parent read uses');
		self::assertSame(['status' => 'open'], $invitations['filter'], 'a child with a time, or a closed round, asks nothing');
		self::assertFalse($invitations['listable']);
		self::assertSame('substantial', $invitations['minTrust']);

		$overview = array_column($manifest['pages'], null, 'id')['parentOverview'];
		$tasks = array_values(array_filter($overview['blocks'], static fn (array $b): bool => $b['type'] === 'tasks'))[0];
		self::assertSame('parentConferenceInvitations', $tasks['collection']);
		preg_match_all('/\{([A-Za-z_]+)\}/', $tasks['titleTemplate'], $places);
		self::assertSame(['childName'], $places[1]);
		$names = array_merge($invitations['fields'], array_column($tasks['lookups'], 'as'));
		foreach (array_merge($places[1], $tasks['titleFields'], [$tasks['dueField']], array_column($tasks['lookups'], 'rowField')) as $field) {
			self::assertContains($field, $names, $field);
		}

		self::assertSame('parentChildren', $tasks['lookups'][0]['collection'], 'the name comes from her own children only');
	}//end testTheGuardianTaskIsOnePerChildAndNamesTheChild()

	/**
	 * The task's button opens the booking page: the only page that shows the
	 * invitations, with the invitation and both booking forms.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-guardian-reads-one-task-per-child-with-the-childs-name
	 */
	public function testTheTaskOpensThePageWithTheBookingForms(): void {
		$manifest = self::manifest(audience: 'parent');
		$showing = array_values(
			array_filter(
				$manifest['pages'],
				static fn (array $page): bool => array_filter(
					$page['blocks'],
					static fn (array $b): bool => ($b['collection'] ?? '') === 'parentConferenceInvitations' && in_array($b['type'], ['collection', 'detail'], true)
				) !== []
			)
		);

		self::assertSame(['parentPickATime'], array_column($showing, 'id'));
		self::assertFalse($showing[0]['menu']);
		self::assertSame(['bookConferenceSlot', 'createConferenceSignup'], array_values(array_filter(array_column($showing[0]['blocks'], 'action'))));
		$actions = array_column($manifest['actions'], 'id');
		self::assertContains('bookConferenceSlot', $actions);
		self::assertContains('createConferenceSignup', $actions);
	}//end testTheTaskOpensThePageWithTheBookingForms()

	/**
	 * "Nu invullen": an update of her own row's estimate, nothing else. No
	 * fixed value, no other field, scoped to her own learnerRef, and the
	 * choices are exactly the schema's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-student-fills-in-her-own-estimate-per-work-process
	 */
	public function testSheFillsInOnlyHerOwnEstimate(): void {
		$manifest = self::manifest(audience: 'student');
		$action = array_column($manifest['actions'], null, 'id')[StudentSelfAssessment::ACTION];

		self::assertSame('update', $action['type']);
		self::assertSame('werkproces-progress', $action['schema']);
		self::assertSame(['learnerRef', 'learnerRef'], [$action['scopeField'], $action['scopeClaim']]);
		self::assertSame(['selfAssessment'], $action['fields'], 'not the hours, not anything else');
		self::assertArrayNotHasKey('set', $action);

		$schema = self::shippedSchema(slug: 'werkproces-progress');
		$enum = array_values(array_filter($schema['properties']['selfAssessment']['enum'], 'is_string'));
		self::assertSame($enum, array_column($action['optionsProviders']['selfAssessment']['options'], 'value'));

		$collection = array_column($manifest['collections'], null, 'id')['studentWorkProcesses'];
		self::assertContains(StudentSelfAssessment::ACTION, $collection['rowActions']);
		self::assertSame($action['scopeField'], $collection['scopeField']);

		// The row the update leaves behind passes the real schema.
		$objects = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/profiles/mbo.json'), true)['x-openregister']['seedData']['objects'];
		$open = array_values(array_filter($objects['werkproces-progress'], static fn (array $row): bool => isset($row['selfAssessment']) === false));
		self::assertNotEmpty($open, 'the board has rows still to fill in');
		$row = $open[0];
		unset($row['@self'], $row['uuid'], $row['slug']);
		foreach ($enum as $value) {
			self::assertNull(self::schemaError('werkproces-progress', array_merge($row, ['selfAssessment' => $value])), $value);
		}
	}//end testSheFillsInOnlyHerOwnEstimate()

	/**
	 * "Volgende stap" carries "Zelfbeoordeling afmaken", opening her
	 * self-assessment of the open placement: her work processes with the
	 * hours and her estimate. The placement page stays where a placement link
	 * lands.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-next-step-card-opens-her-self-assessment
	 */
	public function testTheNextStepOpensHerSelfAssessment(): void {
		$pages = array_column(self::manifest(audience: 'student')['pages'], null, 'id');
		$highlight = array_values(
			array_filter($pages['studentBpvPlacements']['blocks'], static fn (array $b): bool => $b['type'] === 'steps' && ($b['display'] ?? '') === 'highlight')
		)[0];

		self::assertSame('Finish your self-assessment', $highlight['buttonLabel']);
		self::assertSame(StudentSelfAssessment::PAGE, $highlight['page']);
		self::assertTrue($highlight['withRecord']);

		$page = $pages[StudentSelfAssessment::PAGE];
		self::assertFalse($page['menu']);
		self::assertSame('studentBpvPlacements', $page['record']['collection']);
		self::assertSame(
			[['studentWorkProcesses', 'bpvPlacementId']],
			array_map(static fn (array $b): array => [$b['collection'], $b['recordField']], $page['blocks'])
		);
		foreach ($page['blocks'] as $block) {
			self::assertNotSame('studentBpvPlacements', $block['collection'], 'a placement link still opens the placement page');
		}
	}//end testTheNextStepOpensHerSelfAssessment()
}//end class
