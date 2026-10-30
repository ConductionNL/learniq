<?php

/**
 * ProctoringFlagReviewGuard with the real ObjectCreatingEvent and ObjectUpdatingEvent.
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
 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\Learniq\Listener\ProctoringFlagReviewGuard;
use OCA\Learniq\Proctoring\FlagReview;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\EventDispatcher\Event;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Learners append, only staff decide, the server stamps who decided.
 */
class ProctoringFlagReviewGuardTest extends TestCase {

	/**
	 * The guard, written by this caller.
	 *
	 * @param string             $uid     The writer, or '' for no session.
	 * @param array<int, string> $groups  The writer's groups.
	 * @param bool               $isAdmin Whether the writer is an admin.
	 * @param string             $schema  The schema the entity resolves to.
	 *
	 * @return ProctoringFlagReviewGuard
	 */
	private function guard(string $uid, array $groups = [], bool $isAdmin = false, string $schema = 'proctoring-session'): ProctoringFlagReviewGuard {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		if ($schema === 'throws') {
			$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('schema lookup failed'));
		} else {
			$resolver->method('guardSchemaSlug')->willReturn($schema);
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $user, string $group): bool => in_array($group, $groups, true)
		);

		return new ProctoringFlagReviewGuard(new FlagReview(), $resolver, $session, $groupManager);
	}//end guard()

	/**
	 * A flag.
	 *
	 * @param string               $flagId The flag id.
	 * @param array<string, mixed> $extra  Fields to set.
	 *
	 * @return array<string, mixed>
	 */
	private function flag(string $flagId, array $extra = []): array {
		return array_merge(
			['flagId' => $flagId, 'kind' => 'fullscreen-exit', 'occurredAt' => '2026-10-06T10:14:00+00:00', 'severity' => 'medium', 'reviewDecision' => 'pending'],
			$extra
		);
	}//end flag()

	/**
	 * Update a session through the real updating event.
	 *
	 * @param ProctoringFlagReviewGuard        $guard The listener.
	 * @param array<int, array<string, mixed>> $old   The stored flags.
	 * @param array<int, array<string, mixed>> $new   The flags in the write.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(ProctoringFlagReviewGuard $guard, array $old, array $new): ObjectUpdatingEvent {
		$base = ['id' => 's-1', 'learnerId' => 'j.bakker', 'provider' => 'native', 'lifecycle' => 'active'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($base, ['flags' => $new]), 'proctoring-session'),
			OrEntityFactory::make(array_merge($base, ['flags' => $old]), 'proctoring-session')
		);
		$guard->handle($event);

		return $event;
	}//end update()

	/**
	 * Native test mode: a learner appends a pending flag.
	 *
	 * @return void
	 */
	public function testALearnerAppendsAPendingFlag(): void {
		$event = $this->update($this->guard('j.bakker', ['learners']), [$this->flag('f1')], [$this->flag('f1'), $this->flag('f2', ['kind' => 'window-blur'])]);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('pending', $event->getModifiedData()['flags'][1]['reviewDecision']);
		self::assertNull($event->getModifiedData()['flags'][1]['reviewedBy']);
	}//end testALearnerAppendsAPendingFlag()

	/**
	 * A learner who allows their own flag is refused.
	 *
	 * @return void
	 */
	public function testALearnerCannotDecideTheirOwnFlag(): void {
		$event = $this->update($this->guard('j.bakker', ['learners']), [$this->flag('f1')], [$this->flag('f1', ['reviewDecision' => 'allowed'])]);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(FlagReview::ONLY_STAFF, $event->getErrors()['message']);
	}//end testALearnerCannotDecideTheirOwnFlag()

	/**
	 * A learner cannot append a flag that arrives already allowed.
	 *
	 * @return void
	 */
	public function testALearnerCannotAppendADecidedFlag(): void {
		$event = $this->update($this->guard('j.bakker'), [], [$this->flag('f9', ['reviewDecision' => 'allowed', 'reviewedBy' => 'p.jansen'])]);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(FlagReview::ONLY_STAFF, $event->getErrors()['message']);
	}//end testALearnerCannotAppendADecidedFlag()

	/**
	 * A learner cannot remove or rewrite a stored flag.
	 *
	 * @return void
	 */
	public function testALearnerCannotRemoveOrRewriteAFlag(): void {
		$removed = $this->update($this->guard('j.bakker'), [$this->flag('f1'), $this->flag('f2')], [$this->flag('f2')]);
		self::assertSame(FlagReview::NO_REMOVAL, $removed->getErrors()['message']);

		$rewritten = $this->update($this->guard('j.bakker'), [$this->flag('f1')], [$this->flag('f1', ['severity' => 'low'])]);
		self::assertSame(FlagReview::NO_CHANGE, $rewritten->getErrors()['message']);
	}//end testALearnerCannotRemoveOrRewriteAFlag()

	/**
	 * A learner creating a session with a decided flag is refused too.
	 *
	 * @return void
	 */
	public function testALearnerCannotCreateASessionWithADecidedFlag(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'j.bakker', 'flags' => [$this->flag('f1', ['reviewDecision' => 'annulled'])]], 'proctoring-session'));
		$this->guard('j.bakker')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testALearnerCannotCreateASessionWithADecidedFlag()

	/**
	 * Staff decide, and the server stamps the caller and the time over a forged body.
	 *
	 * @return void
	 */
	public function testStaffDecideAndTheServerStampsWhoDecided(): void {
		$before = new DateTimeImmutable('now');
		$event = $this->update(
			$this->guard('c.devries', ['compliance-officers']),
			[$this->flag('f1'), $this->flag('f2')],
			[$this->flag('f1', ['reviewDecision' => 'annulled', 'reviewedBy' => 'somebody-else', 'reviewedAt' => '2020-01-01T00:00:00+00:00']), $this->flag('f2')]
		);

		self::assertFalse($event->isPropagationStopped());
		$flags = $event->getModifiedData()['flags'];
		self::assertSame('annulled', $flags[0]['reviewDecision']);
		self::assertSame('c.devries', $flags[0]['reviewedBy']);
		self::assertGreaterThanOrEqual($before->getTimestamp() - 1, strtotime((string)$flags[0]['reviewedAt']));
		self::assertSame('pending', $flags[1]['reviewDecision']);
		self::assertNull($flags[1]['reviewedBy']);
	}//end testStaffDecideAndTheServerStampsWhoDecided()

	/**
	 * Nobody forges who decided a flag that was already decided.
	 *
	 * @return void
	 */
	public function testAForgedReviewerOnADecidedFlagIsRestored(): void {
		$stored = $this->flag('f1', ['reviewDecision' => 'allowed', 'reviewedBy' => 'p.jansen', 'reviewedAt' => '2026-10-06T15:20:00+00:00']);
		$event = $this->update($this->guard('t.smit', ['instructors']), [$stored], [array_merge($stored, ['reviewedBy' => 't.smit'])]);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('p.jansen', $event->getModifiedData()['flags'][0]['reviewedBy']);
		self::assertSame('2026-10-06T15:20:00+00:00', $event->getModifiedData()['flags'][0]['reviewedAt']);
	}//end testAForgedReviewerOnADecidedFlagIsRestored()

	/**
	 * A decision is not changed afterwards, and only allowed or annulled count.
	 *
	 * @return void
	 */
	public function testASecondDecisionIsRefused(): void {
		$stored = $this->flag('f1', ['reviewDecision' => 'allowed', 'reviewedBy' => 'p.jansen', 'reviewedAt' => '2026-10-06T15:20:00+00:00']);
		$event = $this->update($this->guard('t.smit', ['instructors']), [$stored], [array_merge($stored, ['reviewDecision' => 'annulled'])]);
		self::assertSame(FlagReview::DECIDED, $event->getErrors()['message']);

		$odd = $this->update($this->guard('t.smit', ['instructors']), [$this->flag('f1')], [$this->flag('f1', ['reviewDecision' => 'maybe'])]);
		self::assertSame(FlagReview::BAD_DECISION, $odd->getErrors()['message']);
	}//end testASecondDecisionIsRefused()

	/**
	 * Admin and system writes, and other schemas, are not checked.
	 *
	 * @return void
	 */
	public function testAdminSystemAndOtherSchemasAreUnchecked(): void {
		$decided = [$this->flag('f1', ['reviewDecision' => 'allowed'])];

		$admin = $this->update($this->guard('admin', [], true), [$this->flag('f1')], $decided);
		self::assertFalse($admin->isPropagationStopped());
		self::assertSame([], $admin->getModifiedData());

		$system = $this->update($this->guard(''), [$this->flag('f1')], $decided);
		self::assertFalse($system->isPropagationStopped());

		$other = $this->update($this->guard('j.bakker', [], false, 'assessment-result'), [$this->flag('f1')], $decided);
		self::assertFalse($other->isPropagationStopped());
	}//end testAdminSystemAndOtherSchemasAreUnchecked()

	/**
	 * An event that is not an object write, a write another listener already
	 * stopped, and a schema that cannot be resolved are all left alone.
	 *
	 * @return void
	 */
	public function testEventsThatAreNotOursAreLeftAlone(): void {
		$plain = new Event();
		$this->guard('j.bakker')->handle($plain);
		self::assertFalse($plain->isPropagationStopped());

		$stopped = new ObjectUpdatingEvent(
			OrEntityFactory::make(['flags' => [$this->flag('f1', ['reviewDecision' => 'allowed'])]], 'proctoring-session'),
			OrEntityFactory::make(['flags' => [$this->flag('f1')]], 'proctoring-session')
		);
		$stopped->setErrors(['message' => 'earlier refusal']);
		$stopped->stopPropagation();
		$this->guard('j.bakker')->handle($stopped);
		self::assertSame('earlier refusal', $stopped->getErrors()['message']);
		self::assertSame([], $stopped->getModifiedData());

		$unresolved = $this->update($this->guard('j.bakker', [], false, 'throws'), [$this->flag('f1')], [$this->flag('f1', ['reviewDecision' => 'allowed'])]);
		self::assertFalse($unresolved->isPropagationStopped());
		self::assertSame([], $unresolved->getModifiedData());
	}//end testEventsThatAreNotOursAreLeftAlone()

	/**
	 * A session whose flags are not a list writes no flags back.
	 *
	 * @return void
	 */
	public function testFlagsThatAreNotAListAreIgnored(): void {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(['learnerId' => 'j.bakker', 'flags' => 'none'], 'proctoring-session'),
			OrEntityFactory::make(['learnerId' => 'j.bakker'], 'proctoring-session')
		);
		$this->guard('j.bakker')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testFlagsThatAreNotAListAreIgnored()

	/**
	 * A flag without a decision is pending, and a stored flag without an id
	 * must come back exactly as stored.
	 *
	 * @return void
	 */
	public function testAFlagWithoutAnIdCannotBeChanged(): void {
		$legacy = ['kind' => 'gaze-away', 'occurredAt' => '2026-10-01T09:00:00+00:00', 'severity' => 'low', 'reviewDecision' => 'pending'];
		$appended = ['flagId' => 'f2', 'kind' => 'window-blur', 'occurredAt' => '2026-10-06T10:20:00+00:00', 'severity' => 'low'];

		$kept = $this->update($this->guard('j.bakker'), [$legacy], [array_reverse($legacy, true), $appended]);
		self::assertFalse($kept->isPropagationStopped());
		self::assertSame('pending', $kept->getModifiedData()['flags'][1]['reviewDecision']);

		$changed = $this->update($this->guard('t.smit', ['instructors']), [$legacy], [array_merge($legacy, ['reviewDecision' => 'allowed'])]);
		self::assertSame(FlagReview::NO_CHANGE, $changed->getErrors()['message']);

		$dropped = $this->update($this->guard('t.smit', ['instructors']), [$legacy], []);
		self::assertSame(FlagReview::NO_CHANGE, $dropped->getErrors()['message']);
	}//end testAFlagWithoutAnIdCannotBeChanged()
}//end class
