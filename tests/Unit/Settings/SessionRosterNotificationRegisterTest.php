<?php

/**
 * Unit tests for the Session.x-openregister-notifications.rosterChanged declaration.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-notifications` is evaluated by
 * OpenRegister core at runtime, which does not live in this repository. This
 * test verifies the declared SHAPE is correct — trigger type, watched
 * actions, recipient fields, and channels — mirroring
 * ReportCardComposerRegisterTest's established pattern.
 *
 * `SessionChangeNoticeHandler` (unchanged by this PR) already materialises
 * `affectedLearnerIds`/`affectedParentIds`/`changedAt` onto the Session for
 * exactly these three actions; this test only asserts the register now
 * declares a rule to consume them.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-roster-notifications/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Session.x-openregister-notifications.rosterChanged declaration.
 */
class SessionRosterNotificationRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * Session declares a rosterChanged notification triggered on a transition,
	 * with all three actions SessionChangeNoticeHandler::WATCHED_ACTIONS watches.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-roster-notifications/specs/timetabling/spec.md#scenario-assigning-a-substitute-teacher-mid-lesson-also-notifies
	 */
	public function testRosterChangedTriggerCoversAllThreeWatchedActions(): void {
		$session = $this->config['components']['schemas']['Session'];

		self::assertArrayHasKey('x-openregister-notifications', $session);
		$rule = $session['x-openregister-notifications']['rosterChanged'];

		self::assertSame('transition', $rule['trigger']['type']);
		self::assertSame(
			['cancel', 'substitute-teacher', 'substitute-teacher-in-progress'],
			$rule['trigger']['action']
		);
		self::assertTrue($rule['enabled']);

	}//end testRosterChangedTriggerCoversAllThreeWatchedActions()

	/**
	 * The rule's recipients are exactly the two fields
	 * SessionChangeNoticeHandler already materialises onto the Session, via
	 * the `kind: field` shape already used by AttendanceFlag.reportDeadlineOverdue.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-roster-notifications/specs/timetabling/spec.md#scenario-cancelling-a-session-notifies-every-affected-learner-and-parent
	 */
	public function testRosterChangedRecipientsAreTheMaterialisedFields(): void {
		$rule = $this->config['components']['schemas']['Session']['x-openregister-notifications']['rosterChanged'];

		self::assertSame(
			[
				['kind' => 'field', 'field' => 'affectedLearnerIds'],
				['kind' => 'field', 'field' => 'affectedParentIds'],
			],
			$rule['recipients']
		);
		self::assertSame(['nc-notification'], $rule['channels']);

	}//end testRosterChangedRecipientsAreTheMaterialisedFields()

	/**
	 * The rule carries both an nl and an en subject.
	 *
	 * @return void
	 */
	public function testRosterChangedHasBothLocales(): void {
		$subject = $this->config['components']['schemas']['Session']['x-openregister-notifications']['rosterChanged']['subject'];

		self::assertNotEmpty($subject['nl']);
		self::assertNotEmpty($subject['en']);

	}//end testRosterChangedHasBothLocales()

	/**
	 * Session's own lifecycle transitions still name exactly these three
	 * actions among its non-terminal moves — the notification's action list
	 * cannot silently drift from the transitions the schema actually declares.
	 *
	 * @return void
	 */
	public function testWatchedActionsAreRealSessionTransitions(): void {
		$transitions = $this->config['components']['schemas']['Session']['x-openregister-lifecycle']['transitions'];
		$actions = $this->config['components']['schemas']['Session']['x-openregister-notifications']['rosterChanged']['trigger']['action'];

		foreach ($actions as $action) {
			self::assertArrayHasKey($action, $transitions, "Session has no transition named \"$action\"");
		}

	}//end testWatchedActionsAreRealSessionTransitions()

	/**
	 * The register's info.version was bumped for this change (0.22.0), and
	 * only needs to have moved forward at least that far — later changes will
	 * bump it again.
	 *
	 * @return void
	 */
	public function testRegisterVersionBumped(): void {
		self::assertTrue(
			version_compare($this->config['info']['version'], '0.22.0', '>='),
			'info.version MUST be at least 0.22.0 (session-roster-notifications\'s own bump) — got ' . $this->config['info']['version']
		);

	}//end testRegisterVersionBumped()
}//end class
