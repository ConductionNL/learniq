<?php

/**
 * ConferenceInvitationAction test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-digital-invitations-are-a-declared-transition-notification-to-the-rounds-invited-learners
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\ConferenceInvitationAction;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The send-invitations transition fills the invited learners from the
 * round's cohorts, by user id and by profile reference.
 *
 * @covers \OCA\Learniq\Lifecycle\Action\ConferenceInvitationAction
 */
class ConferenceInvitationActionTest extends TestCase {

	/**
	 * Two cohorts with one shared learner give three invited learners, each
	 * once, with their profile references; the transition is declared in the
	 * register.
	 *
	 * @return void
	 */
	public function testTheRoundsCohortsAreInvitedOnce(): void {
		$cohorts = [
			'c-1' => ['id' => 'c-1', 'learnerIds' => ['l-1', 'l-2']],
			'c-2' => ['id' => 'c-2', 'learnerIds' => ['l-2', 'l-3']],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static fn (string $id) => isset($cohorts[$id]) === true ? OrEntityFactory::make($cohorts[$id], 'cohort') : null
		);
		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('resolveInTenant')->willReturnCallback(
			static fn (string $learnerId, string $tenantId): ?string => $learnerId === 'l-3' ? null : 'ref-' . $learnerId
		);

		$round = (new ConferenceInvitationAction($objectService, $profiles, new NullLogger()))->execute(
			new LifecycleActionContext(
				objectData: ['cohortIds' => ['c-1', 'c-2'], 'tenant_id' => 't'],
				previousData: [],
				parameters: [],
				actionName: 'OCA\\Learniq\\Lifecycle\\Action\\ConferenceInvitationAction'
			)
		);

		$this->assertSame(['l-1', 'l-2', 'l-3'], $round['invitedLearnerIds']);
		$this->assertSame(['ref-l-1', 'ref-l-2'], $round['invitedLearnerRefs']);

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/learniq_register.json'), true);
		$send = $register['components']['schemas']['ConferenceRound']['x-openregister-lifecycle']['transitions']['send-invitations'];
		$this->assertContains(['action' => ConferenceInvitationAction::class], $send['actions']);
	}//end testTheRoundsCohortsAreInvitedOnce()
	/**
	 * A blank cohort id is never read, an unreadable cohort is logged and
	 * skipped, a missing cohort adds no one, and only non-empty string learner
	 * ids are invited.
	 *
	 * @return void
	 */
	public function testUnusableCohortsAndIdsAreSkipped(): void {
		$asked = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (string $id) use (&$asked) {
				$asked[] = $id;
				if ($id === 'c-broken') {
					throw new RuntimeException('db down');
				}

				if ($id === 'c-gone') {
					return null;
				}

				return OrEntityFactory::make(['id' => $id, 'learnerIds' => ['l-1', '', 7, null, 'l-2']], 'cohort');
			}
		);
		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->expects($this->exactly(2))->method('resolveInTenant')->willReturnCallback(
			static function (string $learnerId, string $tenantId): string {
				self::assertSame('', $tenantId);
				return 'ref-' . $learnerId;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ConferenceInvitationAction] Cohort {cohort} could not be read: {msg}',
			['cohort' => 'c-broken', 'msg' => 'db down']
		);

		$round = (new ConferenceInvitationAction($objectService, $profiles, $logger))->execute(
			new LifecycleActionContext(
				objectData: ['title' => 'Round 1', 'cohortIds' => ['', 'c-broken', 'c-gone', 'c-ok']],
				previousData: [],
				parameters: [],
				actionName: 'send-invitations'
			)
		);

		$this->assertSame(['c-broken', 'c-gone', 'c-ok'], $asked);
		$this->assertSame('Round 1', $round['title']);
		$this->assertSame(['l-1', 'l-2'], $round['invitedLearnerIds']);
		$this->assertSame(['ref-l-1', 'ref-l-2'], $round['invitedLearnerRefs']);
	}//end testUnusableCohortsAndIdsAreSkipped()

	/**
	 * A round without cohorts invites no one and resolves nothing.
	 *
	 * @return void
	 */
	public function testARoundWithoutCohortsInvitesNoOne(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('find');
		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->expects($this->never())->method('resolveInTenant');

		$round = (new ConferenceInvitationAction($objectService, $profiles, new NullLogger()))->execute(
			new LifecycleActionContext(objectData: ['tenant_id' => 't'], previousData: [], parameters: [], actionName: 'send-invitations')
		);

		$this->assertSame(['tenant_id' => 't', 'invitedLearnerIds' => [], 'invitedLearnerRefs' => []], $round);
	}//end testARoundWithoutCohortsInvitesNoOne()
}//end class
