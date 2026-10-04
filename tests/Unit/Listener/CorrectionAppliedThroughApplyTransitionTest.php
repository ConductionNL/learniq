<?php

/**
 * A republish on an approved correction marks it applied through the
 * register's own `apply` transition (live pass D10).
 *
 * Live, after the D9 fix the listener ran and its save was refused:
 * "Cannot modify readOnly properties: appliedAt, appliedBy". The register
 * declares both fields readOnly ("Filled in by the app"), and OpenRegister
 * refuses an update that changes a readOnly property whoever saves, with
 * `_rbac` false too. The handler wrote them in its payload. The way the
 * register fills a readOnly stamp is a declared transition action, which
 * OpenRegister runs on the save path after the readOnly check (decidedBy is
 * written that way by `approve`).
 *
 * This test saves through RegisterFaithfulStore, which refuses readOnly
 * changes with OpenRegister's message and runs the declared transition's
 * guard and actions, with the real handler, guard, stamp action and
 * ObjectTransitionedEvent.
 *
 * @category Test
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Lifecycle\Action\LinkCoveringCorrectionAction;
use OCA\Learniq\Lifecycle\Action\StampTransitionActorAction;
use OCA\Learniq\Lifecycle\DataCorrectionDecisionGuard;
use OCA\Learniq\Listener\CorrectionAppliedHandler;
use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The correction is applied by the declared transition, not by a readOnly write.
 */
class CorrectionAppliedThroughApplyTransitionTest extends TestCase {

	private const ENTRY_ID = 'b760e7e5-0000-4000-8000-000000000001';

	private const REQUEST_ID = '6a1ed10f-0000-4000-8000-000000000001';

	/**
	 * The grade entry lp-teacher revised to the approved value, before the republish.
	 */
	private const ENTRY = [
		'id'                  => self::ENTRY_ID,
		'learnerId'           => 'lp-learner',
		'value'               => 7.0,
		'period'              => 'LP3',
		'grader'              => 'lp-teacher',
		'correctionRequestId' => null,
		'lifecycle'           => 'revised',
	];

	/**
	 * The request as the instance stores it after lp-principal approved it:
	 * the later fields were stamped null at create (DataCorrectionRequestStamp).
	 */
	private const APPROVED = [
		'id'            => self::REQUEST_ID,
		'gradeEntryId'  => self::ENTRY_ID,
		'proposedValue' => 7.0,
		'currentValue'  => 5.5,
		'reason'        => 'A page of the exam was not counted.',
		'requestedBy'   => 'lp-teacher',
		'decisionNote'  => null,
		'decidedBy'     => 'lp-principal',
		'decidedAt'     => '2026-10-03T06:50:00+00:00',
		'appliedBy'     => null,
		'appliedAt'     => null,
		'lifecycle'     => 'approved',
	];

	/**
	 * Map positional arguments onto the parameter names of an ObjectService method.
	 *
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The arguments as received.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(string $method, array $args): array {
		$named = [];
		foreach ((new ReflectionMethod(ObjectService::class, $method))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === true) {
				$named[$parameter->getName()] = $args[$position];
			}
		}

		return $named;
	}//end named()

	/**
	 * ObjectService over the store.
	 *
	 * @param RegisterFaithfulStore $store The store.
	 *
	 * @return ObjectService
	 */
	private function objects(RegisterFaithfulStore $store): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('find')->willReturnCallback(
			static function (mixed ...$args) use ($store): ?ObjectEntity {
				$named = self::named(method: 'find', args: $args);
				$schema = (string)($named['schema'] ?? '');
				foreach (($store->rows[$schema] ?? []) as $row) {
					if (($row['id'] ?? null) === $named['id']) {
						return OrEntityFactory::make($row, $schema);
					}
				}

				return null;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			static function (mixed ...$args) use ($store): ObjectEntity {
				$named = self::named(method: 'saveObject', args: $args);
				$object = $named['object'];
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				return $store->save((string)($named['schema'] ?? ''), $data, $named['uuid'] ?? null);
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The store as the instance holds it at the republish, with the declared
	 * guard and stamp action of the request's lifecycle registered.
	 *
	 * @return array{0: RegisterFaithfulStore, 1: ObjectService}
	 */
	private function instance(): array {
		$store = new RegisterFaithfulStore();
		$store->rows['grade-entry'] = [self::ENTRY];
		$store->rows['data-correction-request'] = [self::APPROVED];
		$objects = $this->objects(store: $store);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('lp-teacher');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$store->actingUser = 'lp-teacher';
		$store->lifecycleActions[StampTransitionActorAction::class] = new StampTransitionActorAction(userSession: $session);
		$store->lifecycleActions[LinkCoveringCorrectionAction::class] = new LinkCoveringCorrectionAction(
			approvals: new CorrectionApprovals(objects: $objects),
			userSession: $session,
			logger: new NullLogger()
		);
		$store->lifecycleGuards[DataCorrectionDecisionGuard::class] = new DataCorrectionDecisionGuard(
			objects: $objects,
			approvals: new CorrectionApprovals(objects: $objects)
		);

		return [$store, $objects];
	}//end instance()

	/**
	 * lp-teacher republishes on lp-principal's approval: the request reads
	 * applied, by lp-teacher, with a time, decidedBy kept, and the entry names
	 * the request. Red on development: the save is refused with
	 * "Cannot modify readOnly properties: appliedBy, appliedAt", logged, and
	 * the request stays approved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function testARepublishMarksTheCorrectionAppliedThroughItsTransition(): void {
		[$store, $objects] = $this->instance();
		$logger = new CapturingLogger();
		$handler = new CorrectionAppliedHandler(
			schemas: TransitionScope::resolver(),
			approvals: new CorrectionApprovals(objects: $objects),
			objects: $objects,
			logger: $logger
		);

		// The republish: TransitionEngine saves the entry at published (the
		// period lock guard is not what this test is about), then dispatches
		// ObjectTransitionedEvent with the saved entry.
		$published = $store->save('grade-entry', array_merge(self::ENTRY, ['lifecycle' => 'published']), self::ENTRY_ID);
		$handler->handle(new ObjectTransitionedEvent(
			$published,
			'republish',
			'revised',
			'published',
			'lp-teacher',
			'learniq',
			'grade-entry'
		));

		self::assertSame([], $logger->records, 'The handler logged a failure: ' . $logger->dump());
		$request = $store->rows['data-correction-request'][0];
		self::assertSame('applied', $request['lifecycle']);
		self::assertSame('lp-teacher', $request['appliedBy']);
		self::assertIsString($request['appliedAt']);
		self::assertNotSame('', $request['appliedAt']);
		self::assertSame('lp-principal', $request['decidedBy']);
		self::assertSame(self::REQUEST_ID, $store->rows['grade-entry'][0]['correctionRequestId'] ?? null, 'The grade entry does not name its correction.');
	}//end testARepublishMarksTheCorrectionAppliedThroughItsTransition()

	/**
	 * The double refuses what OpenRegister refuses: a caller that writes the
	 * readOnly stamp itself, however it saves.
	 *
	 * @return void
	 */
	public function testTheStoreRefusesAReadOnlyStampFromTheCaller(): void {
		[$store] = $this->instance();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot modify readOnly properties: appliedBy, appliedAt');
		$store->save(
			'data-correction-request',
			array_merge(self::APPROVED, ['lifecycle' => 'applied', 'appliedBy' => 'lp-teacher', 'appliedAt' => '2026-10-03T07:00:00+00:00']),
			self::REQUEST_ID
		);
	}//end testTheStoreRefusesAReadOnlyStampFromTheCaller()

	/**
	 * The entry link is readOnly too: a caller's second save refuses it the
	 * same way, so the republish's own action writes it.
	 *
	 * @return void
	 */
	public function testTheStoreRefusesTheEntryLinkFromTheCaller(): void {
		[$store] = $this->instance();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot modify readOnly property: correctionRequestId');
		$store->save('grade-entry', array_merge(self::ENTRY, ['correctionRequestId' => self::REQUEST_ID]), self::ENTRY_ID);
	}//end testTheStoreRefusesTheEntryLinkFromTheCaller()

	/**
	 * A republish no approved correction covers keeps the entry unlinked.
	 *
	 * @return void
	 */
	public function testARepublishWithoutACorrectionLinksNothing(): void {
		[$store] = $this->instance();
		$store->rows['data-correction-request'] = [];

		$store->save('grade-entry', array_merge(self::ENTRY, ['lifecycle' => 'published']), self::ENTRY_ID);

		self::assertNull($store->rows['grade-entry'][0]['correctionRequestId']);
	}//end testARepublishWithoutACorrectionLinksNothing()

	/**
	 * The register's `apply` transition stamps the publisher and the time, and
	 * the grade entry's `republish` links the correction, so each readOnly
	 * field has a writer at all.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresTheWriters(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schemas = $register['components']['schemas'];
		$apply = $schemas['DataCorrectionRequest']['x-openregister-lifecycle']['transitions']['apply'];
		$republish = $schemas['GradeEntry']['x-openregister-lifecycle']['transitions']['republish'];
		self::assertContains(['action' => LinkCoveringCorrectionAction::class], ($republish['actions'] ?? []));

		self::assertSame('approved', $apply['from']);
		self::assertSame('applied', $apply['to']);
		self::assertContains(
			['action' => StampTransitionActorAction::class, 'actionParameters' => ['actorField' => 'appliedBy', 'timeField' => 'appliedAt']],
			($apply['actions'] ?? [])
		);
	}//end testTheRegisterDeclaresTheWriters()
}//end class
