<?php

/**
 * Learniq LearningRecordCreatedTransition unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-learner-creates-a-share-with-a-mandatory-expiry
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\LearningRecordCreatedTransition;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for LearningRecordCreatedTransition::handle().
 */
class LearningRecordCreatedTransitionTest extends TestCase {

	private const ID = 'cccccccc-0000-4000-8000-000000000003';

	/**
	 * The transition engine the listener calls.
	 *
	 * @var TransitionEngine&MockObject
	 */
	private TransitionEngine&MockObject $engine;

	/**
	 * The logger the listener writes a failed transition to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the listener, signed in as $userId; 'admin' is an administrator.
	 *
	 * @param string|null $userId The session user, null for no session.
	 * @param string      $slug   What the schema resolver answers.
	 *
	 * @return LearningRecordCreatedTransition
	 */
	private function makeListener(?string $userId, string $slug): LearningRecordCreatedTransition {
		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$this->engine = $this->createMock(TransitionEngine::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new LearningRecordCreatedTransition(
			schemaResolver: $schemaResolver,
			transitionEngine: $this->engine,
			groupManager: $groups,
			userSession: $session,
			logger: $this->logger,
		);
	}//end makeListener()

	/**
	 * A learner's new export is generated in the same request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
	 */
	public function testANewExportIsGenerated(): void {
		$listener = $this->makeListener(userId: 'learner', slug: 'learning-record-export');
		$this->engine->expects($this->once())->method('transition')->with(self::ID, 'generate');

		$listener->handle(new ObjectCreatedEvent(OrEntityFactory::make(['id' => self::ID, 'learnerId' => 'learner', 'lifecycle' => 'requested'], 'learning-record-export')));
	}//end testANewExportIsGenerated()

	/**
	 * A learner's new share is granted in the same request.
	 *
	 * @return void
	 */
	public function testANewShareIsGranted(): void {
		$listener = $this->makeListener(userId: 'learner', slug: 'learning-record-share');
		$this->engine->expects($this->once())->method('transition')->with(self::ID, 'grant');

		$listener->handle(new ObjectCreatedEvent(OrEntityFactory::make(['id' => self::ID, 'learnerId' => 'learner', 'lifecycle' => 'draft'], 'learning-record-share')));
	}//end testANewShareIsGranted()

	/**
	 * A failed transition leaves the row in its first state and is logged,
	 * so the screen reads that state and says it failed.
	 *
	 * @return void
	 */
	public function testAFailedTransitionIsLoggedAndLeavesTheRow(): void {
		$listener = $this->makeListener(userId: 'learner', slug: 'learning-record-export');
		$this->engine->method('transition')->willThrowException(new RuntimeException('No signing key.'));
		$this->logger->expects($this->once())->method('warning');

		$listener->handle(new ObjectCreatedEvent(OrEntityFactory::make(['id' => self::ID, 'learnerId' => 'learner', 'lifecycle' => 'requested'], 'learning-record-export')));
	}//end testAFailedTransitionIsLoggedAndLeavesTheRow()

	/**
	 * Seed imports, administrators, rows of another person and rows already
	 * past their first state are not moved.
	 *
	 * @return void
	 */
	public function testOnlyTheLearnersOwnNewRowMoves(): void {
		$cases = [
			[null, ['learnerId' => 'learner', 'lifecycle' => 'requested']],
			['admin', ['learnerId' => 'admin', 'lifecycle' => 'requested']],
			['learner', ['learnerId' => 'classmate', 'lifecycle' => 'requested']],
			['learner', ['learnerId' => 'learner', 'lifecycle' => 'generated']],
		];
		foreach ($cases as [$userId, $row]) {
			$listener = $this->makeListener(userId: $userId, slug: 'learning-record-export');
			$this->engine->expects($this->never())->method('transition');
			$listener->handle(new ObjectCreatedEvent(OrEntityFactory::make(array_merge(['id' => self::ID], $row), 'learning-record-export')));
		}
	}//end testOnlyTheLearnersOwnNewRowMoves()

	/**
	 * Another schema's create is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$listener = $this->makeListener(userId: 'learner', slug: 'enrolment');
		$this->engine->expects($this->never())->method('transition');

		$listener->handle(new ObjectCreatedEvent(OrEntityFactory::make(['id' => self::ID, 'learnerId' => 'learner', 'lifecycle' => 'requested'], 'enrolment')));
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The listener is wired on create.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegisteredOnCreated(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatedEvent::class . ' => ' . LearningRecordCreatedTransition::class, $registered);
	}//end testTheListenerIsRegisteredOnCreated()
}//end class
