<?php

/**
 * Learniq LearnerUserIdStamp unit tests.
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
 * @spec openspec/specs/external-training-recording/spec.md#requirement-verification-must-be-gated-and-tamper-resistant
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\LearnerUserIdStamp;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for LearnerUserIdStamp::handle().
 */
class LearnerUserIdStampTest extends TestCase {

	private const PROFILE_A = '1a2b3c4d-0000-4000-8000-00000000000a';
	private const PROFILE_B = '1a2b3c4d-0000-4000-8000-00000000000b';
	private const PROFILE_MERGED = '1a2b3c4d-0000-4000-8000-00000000000c';

	/**
	 * When set, every profile read throws this message.
	 *
	 * @var string|null
	 */
	private ?string $failReads = null;

	/**
	 * How many profile reads were made.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Build the stamp over a real LearnerRefResolver and a profile store.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return LearnerUserIdStamp
	 */
	private function makeStamp(string $slug = 'external-training-record'): LearnerUserIdStamp {
		$profiles = [
			self::PROFILE_A => ['id' => self::PROFILE_A, 'ncUserId' => 'a.devries', 'lifecycle' => 'active'],
			self::PROFILE_B => ['id' => self::PROFILE_B, 'ncUserId' => 'b.bakker', 'lifecycle' => 'active'],
			self::PROFILE_MERGED => ['id' => self::PROFILE_MERGED, 'ncUserId' => 'c.old', 'lifecycle' => 'active', 'mergedInto' => self::PROFILE_A],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($profiles): ObjectEntity {
				$this->reads++;
				if ($this->failReads !== null) {
					throw new RuntimeException($this->failReads);
				}

				if ($schema === 'learner-profile' && isset($profiles[(string)$id]) === true) {
					return OrEntityFactory::make($profiles[(string)$id], 'learner-profile');
				}

				throw new DoesNotExistException('gone');
			}
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new LearnerUserIdStamp(
			schemaResolver: $schemaResolver,
			profiles: new LearnerRefResolver(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * A creating event for a record.
	 *
	 * @param array<string, mixed> $data Payload.
	 * @param string               $slug The schema slug.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creating(array $data, string $slug = 'external-training-record'): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make($data, $slug));
	}//end creating()

	/**
	 * An updating event for a record.
	 *
	 * @param array<string, mixed> $new New state.
	 * @param array<string, mixed> $old Stored state.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(array $new, array $old): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			OrEntityFactory::make($new, 'external-training-record'),
			OrEntityFactory::make($old, 'external-training-record')
		);
	}//end updating()

	/**
	 * A record created with a profile uuid gets that profile's user id.
	 *
	 * @return void
	 */
	public function testACreateGetsTheProfilesUserId(): void {
		$event = $this->creating(['learnerId' => self::PROFILE_A, 'title' => 'BHV']);
		$this->makeStamp()->handle($event);

		self::assertSame('a.devries', $event->getModifiedData()['learnerUserId']);
		self::assertFalse($event->isPropagationStopped());
	}//end testACreateGetsTheProfilesUserId()

	/**
	 * A learnerUserId sent by the client is replaced by the derived one.
	 *
	 * @return void
	 */
	public function testAForgedUserIdIsReplaced(): void {
		$event = $this->creating(['learnerId' => self::PROFILE_A, 'learnerUserId' => 'b.bakker']);
		$this->makeStamp()->handle($event);

		self::assertSame('a.devries', $event->getModifiedData()['learnerUserId']);
	}//end testAForgedUserIdIsReplaced()

	/**
	 * An unknown, merged-away or non-uuid learnerId gives null: no user
	 * matches, and the write goes through.
	 *
	 * @return void
	 */
	public function testNoActiveProfileGivesNull(): void {
		foreach (['1a2b3c4d-0000-4000-8000-0000000000ff', self::PROFILE_MERGED, 'a.devries'] as $learnerId) {
			$event = $this->creating(['learnerId' => $learnerId, 'learnerUserId' => 'b.bakker']);
			$this->makeStamp()->handle($event);

			self::assertArrayHasKey('learnerUserId', $event->getModifiedData());
			self::assertNull($event->getModifiedData()['learnerUserId'], $learnerId);
			self::assertFalse($event->isPropagationStopped());
		}
	}//end testNoActiveProfileGivesNull()

	/**
	 * An update re-derives the value from the new learner.
	 *
	 * @return void
	 */
	public function testAnUpdateReDerivesFromTheNewLearner(): void {
		$event = $this->updating(
			['id' => 'etr-1', 'learnerId' => self::PROFILE_B, 'learnerUserId' => 'a.devries'],
			['id' => 'etr-1', 'learnerId' => self::PROFILE_A, 'learnerUserId' => 'a.devries']
		);
		$this->makeStamp()->handle($event);

		self::assertSame('b.bakker', $event->getModifiedData()['learnerUserId']);
	}//end testAnUpdateReDerivesFromTheNewLearner()

	/**
	 * A failed lookup on update keeps the stored value for the same learner,
	 * and fails closed when the record moves to another learner.
	 *
	 * @return void
	 */
	public function testAFailedLookupKeepsTheStoredValueOnlyForTheSameLearner(): void {
		$stamp = $this->makeStamp();
		$this->failReads = 'database gone';

		$same = $this->updating(
			['id' => 'etr-1', 'learnerId' => self::PROFILE_A, 'learnerUserId' => 'b.bakker'],
			['id' => 'etr-1', 'learnerId' => self::PROFILE_A, 'learnerUserId' => 'a.devries']
		);
		$stamp->handle($same);
		self::assertSame('a.devries', $same->getModifiedData()['learnerUserId']);

		$moved = $this->updating(
			['id' => 'etr-1', 'learnerId' => self::PROFILE_B],
			['id' => 'etr-1', 'learnerId' => self::PROFILE_A, 'learnerUserId' => 'a.devries']
		);
		$stamp->handle($moved);
		self::assertNull($moved->getModifiedData()['learnerUserId']);

		$created = $this->creating(['learnerId' => self::PROFILE_A]);
		$stamp->handle($created);
		self::assertNull($created->getModifiedData()['learnerUserId']);
		self::assertFalse($created->isPropagationStopped());
	}//end testAFailedLookupKeepsTheStoredValueOnlyForTheSameLearner()

	/**
	 * Another schema's write, and a write already refused, are left alone.
	 *
	 * @return void
	 */
	public function testOtherWritesAreUntouched(): void {
		$other = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => self::PROFILE_A], 'credential'));
		$this->makeStamp(slug: 'credential')->handle($other);
		self::assertSame([], $other->getModifiedData());

		$refused = $this->creating(['learnerId' => self::PROFILE_A]);
		$refused->stopPropagation();
		$this->makeStamp()->handle($refused);
		self::assertSame([], $refused->getModifiedData());
		self::assertSame(0, $this->reads);
	}//end testOtherWritesAreUntouched()

	/**
	 * An ExternalTrainingRecord without learnerRef gets the profile uuid as
	 * learnerRef, so it reaches the learnerRef-scoped reads; one that has a
	 * learnerRef keeps it.
	 *
	 * @return void
	 */
	public function testAnEmptyLearnerRefIsFilledWithTheProfile(): void {
		$empty = $this->creating(['learnerId' => self::PROFILE_A]);
		$this->makeStamp()->handle($empty);
		self::assertSame(self::PROFILE_A, $empty->getModifiedData()['learnerRef']);

		$blank = $this->creating(['learnerId' => self::PROFILE_A, 'learnerRef' => '']);
		$this->makeStamp()->handle($blank);
		self::assertSame(self::PROFILE_A, $blank->getModifiedData()['learnerRef']);

		$set = $this->creating(['learnerId' => self::PROFILE_A, 'learnerRef' => self::PROFILE_B]);
		$this->makeStamp()->handle($set);
		self::assertArrayNotHasKey('learnerRef', $set->getModifiedData());
	}//end testAnEmptyLearnerRefIsFilledWithTheProfile()

	/**
	 * An ExemptionCase gets learnerUserId and no learnerRef (it declares none).
	 *
	 * @return void
	 */
	public function testAnExemptionCaseGetsTheLearnersUserId(): void {
		$event = $this->creating(['learnerId' => self::PROFILE_B, 'learnerUserId' => 'a.devries'], 'exemption-case');
		$this->makeStamp(slug: 'exemption-case')->handle($event);

		self::assertSame(['learnerUserId' => 'b.bakker'], $event->getModifiedData());
	}//end testAnExemptionCaseGetsTheLearnersUserId()

	/**
	 * A FraudCase gets the accused learner's user id; the reporter is left alone.
	 *
	 * @return void
	 */
	public function testAFraudCaseGetsTheAccusedLearnersUserId(): void {
		$event = $this->creating(['accusedLearnerId' => self::PROFILE_A, 'reporterId' => 'teacher-1', 'learnerId' => self::PROFILE_B], 'fraud-case');
		$this->makeStamp(slug: 'fraud-case')->handle($event);

		self::assertSame(['accusedLearnerUserId' => 'a.devries'], $event->getModifiedData());
	}//end testAFraudCaseGetsTheAccusedLearnersUserId()

	/**
	 * The stamp is wired on both create and update, asserted from the caller.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . LearnerUserIdStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . LearnerUserIdStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
