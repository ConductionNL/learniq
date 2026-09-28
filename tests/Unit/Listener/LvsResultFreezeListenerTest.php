<?php

/**
 * Unit tests for LvsResultFreezeListener (lvs-score-freeze).
 *
 * LvsResult dropped `appendOnly` in learniq#1124 so `verify` and `archive`
 * could run, which also let coordinators and compliance officers edit an
 * imported score after it was verified. These tests pin the freeze.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\LvsResultFreezeListener;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the verified LVS score freeze.
 */
class LvsResultFreezeListenerTest extends TestCase {

	/**
	 * Build the listener for a caller.
	 *
	 * @param string $uid The caller's user id, or '' for no session.
	 * @param bool $isAdmin Whether the caller is an instance admin.
	 * @param string $schemaSlug The schema the event's entity resolves to.
	 *
	 * @return LvsResultFreezeListener
	 */
	private function makeListener(string $uid = 'coordinator1', bool $isAdmin = false, string $schemaSlug = 'lvs-result'): LvsResultFreezeListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new LvsResultFreezeListener(
			schemaResolver: $resolver,
			userSession: $session,
			groupManager: $groupManager,
			logger: new NullLogger(),
		);
	}//end makeListener()

	/**
	 * An LVS result in a lifecycle state.
	 *
	 * @param string $lifecycle The state.
	 * @param array<string,mixed> $override Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function lvs(string $lifecycle, array $override = []): array {
		return array_merge(
			[
				'id' => 'lvs1',
				'provider' => 'cito',
				'instrument' => 'Rekenen-Wiskunde',
				'moment' => 'M6',
				'takenAt' => '2026-01-20',
				'rawScore' => 42,
				'vaardigheidsscore' => 187,
				'niveau' => 'II',
				'referentieniveau' => '1F',
				'dle' => 25,
				'learnerId' => 'learner1',
				'tenant_id' => 't1',
				'lifecycle' => $lifecycle,
			],
			$override
		);
	}//end lvs()

	/**
	 * Run an update through the listener.
	 *
	 * @param LvsResultFreezeListener $listener The listener.
	 * @param array<string,mixed> $old The stored object.
	 * @param array<string,mixed> $new The object as it would be saved.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(LvsResultFreezeListener $listener, array $old, array $new): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make($new, 'lvs-result'),
			OrEntityFactory::make($old, 'lvs-result')
		);
		$listener->handle($event);
		return $event;
	}//end update()

	/**
	 * A coordinator cannot change the score of a verified result.
	 *
	 * @return void
	 */
	public function testCoordinatorCannotChangeAVerifiedScore(): void {
		$event = $this->update($this->makeListener(), $this->lvs('verified'), $this->lvs('verified', ['vaardigheidsscore' => 201]));

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('lvs-result-verified', $event->getErrors()['reason']);
	}//end testCoordinatorCannotChangeAVerifiedScore()

	/**
	 * Every score field is frozen, not only the scale score.
	 *
	 * @return void
	 */
	public function testEveryScoreFieldIsFrozen(): void {
		$changes = [
			'provider' => 'iep',
			'instrument' => 'Taal',
			'moment' => 'E6',
			'takenAt' => '2026-01-21',
			'rawScore' => 43,
			'niveau' => 'I',
			'referentieniveau' => '2F',
			'dle' => 30,
			'tenant_id' => 't2',
		];
		foreach ($changes as $field => $value) {
			$event = $this->update($this->makeListener(), $this->lvs('verified'), $this->lvs('verified', [$field => $value]));
			self::assertTrue($event->isPropagationStopped(), $field . ' was not frozen');
		}
	}//end testEveryScoreFieldIsFrozen()

	/**
	 * An imported result may still be corrected before it is verified.
	 *
	 * @return void
	 */
	public function testAnImportedScoreMayBeCorrected(): void {
		$event = $this->update($this->makeListener(), $this->lvs('imported'), $this->lvs('imported', ['vaardigheidsscore' => 201]));

		self::assertFalse($event->isPropagationStopped());
	}//end testAnImportedScoreMayBeCorrected()

	/**
	 * The verify transition itself goes through.
	 *
	 * @return void
	 */
	public function testVerifyGoesThrough(): void {
		$event = $this->update($this->makeListener(), $this->lvs('imported'), $this->lvs('verified'));

		self::assertFalse($event->isPropagationStopped());
	}//end testVerifyGoesThrough()

	/**
	 * A verified result may be archived, linked to an assessment result, and
	 * re-pointed by a learner merge, and an integer score equal to a float
	 * score is no change.
	 *
	 * @return void
	 */
	public function testArchiveLinkMergeAndSameScoreGoThrough(): void {
		$old = $this->lvs('verified');
		foreach ([
			$this->lvs('archived'),
			$this->lvs('verified', ['assessmentResultId' => 'ar1']),
			$this->lvs('verified', ['learnerId' => 'learner2']),
			$this->lvs('verified', ['vaardigheidsscore' => 187.0]),
		] as $new) {
			$event = $this->update($this->makeListener(), $old, $new);
			self::assertFalse($event->isPropagationStopped(), json_encode($event->getErrors()));
		}
	}//end testArchiveLinkMergeAndSameScoreGoThrough()

	/**
	 * A verified result cannot be sent back to imported to be edited.
	 *
	 * @return void
	 */
	public function testAVerifiedResultCannotGoBackToImported(): void {
		$event = $this->update($this->makeListener(), $this->lvs('verified'), $this->lvs('imported'));

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('lvs-result-lifecycle', $event->getErrors()['reason']);
	}//end testAVerifiedResultCannotGoBackToImported()

	/**
	 * An archived result is final.
	 *
	 * @return void
	 */
	public function testAnArchivedResultIsFinal(): void {
		$listener = $this->makeListener();

		self::assertTrue($this->update($listener, $this->lvs('archived'), $this->lvs('verified'))->isPropagationStopped());
		self::assertTrue($this->update($listener, $this->lvs('archived'), $this->lvs('archived', ['dle' => 1]))->isPropagationStopped());
	}//end testAnArchivedResultIsFinal()

	/**
	 * Admins, system context and other schemas are not policed.
	 *
	 * @return void
	 */
	public function testAdminSystemAndOtherSchemasAreNotPoliced(): void {
		$old = $this->lvs('verified');
		$new = $this->lvs('verified', ['vaardigheidsscore' => 201]);

		self::assertFalse($this->update($this->makeListener(isAdmin: true), $old, $new)->isPropagationStopped());
		self::assertFalse($this->update($this->makeListener(uid: ''), $old, $new)->isPropagationStopped());
		self::assertFalse($this->update($this->makeListener(schemaSlug: 'grade-entry'), $old, $new)->isPropagationStopped());
	}//end testAdminSystemAndOtherSchemasAreNotPoliced()

	/**
	 * The freeze is wired on update, so it holds for every writer.
	 *
	 * @return void
	 */
	public function testTheFreezeIsRegisteredOnUpdate(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectUpdatingEvent::class . ' => ' . LvsResultFreezeListener::class, $pairs);
	}//end testTheFreezeIsRegisteredOnUpdate()

	/**
	 * The register no longer claims LvsResult is append-only.
	 *
	 * @return void
	 */
	public function testTheRegisterDescribesTheFreezeNotAppendOnly(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);
		$description = $register['components']['schemas']['LvsResult']['description'];

		self::assertStringNotContainsString('Append-only', $description);
		self::assertStringContainsString('LvsResultFreezeListener', $description);
	}//end testTheRegisterDescribesTheFreezeNotAppendOnly()
}//end class
