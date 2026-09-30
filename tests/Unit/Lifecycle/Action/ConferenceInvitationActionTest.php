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
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The send-invitations transition fills the invited learners from the
 * round's cohorts, by user id and by profile reference.
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
			['cohortIds' => ['c-1', 'c-2'], 'tenant_id' => 't'],
			[],
			[],
			'OCA\\Learniq\\Lifecycle\\Action\\ConferenceInvitationAction'
		);

		$this->assertSame(['l-1', 'l-2', 'l-3'], $round['invitedLearnerIds']);
		$this->assertSame(['ref-l-1', 'ref-l-2'], $round['invitedLearnerRefs']);

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/learniq_register.json'), true);
		$send = $register['components']['schemas']['ConferenceRound']['x-openregister-lifecycle']['transitions']['send-invitations'];
		$this->assertContains(['action' => ConferenceInvitationAction::class], $send['actions']);
	}//end testTheRoundsCohortsAreInvitedOnce()
}//end class
