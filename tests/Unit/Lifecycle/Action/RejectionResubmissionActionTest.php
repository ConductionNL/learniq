<?php

/**
 * Learniq RejectionResubmissionAction unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\RejectionResubmissionAction;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The writing half of ExchangeRejection.resubmit (learniq#983):
 * RejectionResubmitGuard only checks, this action creates the one scoped
 * DataExchangeJob and writes its id to `resubmittedJobId` on the saved rejection.
 */
class RejectionResubmissionActionTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var list<array{register: mixed, schema: mixed, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Reset the capture buffer before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];
	}//end setUp()

	/**
	 * Build the action over an ObjectService that resolves the originating job
	 * and records saves, and a session holding the given user.
	 *
	 * @param array<string,mixed>|null $originalJob The originating job findAll() returns, or null.
	 * @param string|null              $newJobId    Id of the saved job, or null for a save without one.
	 * @param string|null              $uid         The session user's uid, or null for no session.
	 *
	 * @return RejectionResubmissionAction
	 */
	private function makeAction(?array $originalJob, ?string $newJobId = 'new-job-1', ?string $uid = 'actor-1'): RejectionResubmissionAction {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($originalJob): array {
				if (($config['filters']['schema'] ?? '') !== 'data-exchange-job' || $originalJob === null) {
					return [];
				}

				return OrEntityFactory::makeMany([$originalJob], 'data-exchange-job');
			}
		);

		// saveObject($object, $extend, $register, $schema, ...): the payload is first.
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) use ($newJobId): ObjectEntity {
				$this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

				if ($newJobId === null) {
					return OrEntityFactory::make($object, 'data-exchange-job', 'learniq', null);
				}

				return OrEntityFactory::make(array_merge($object, ['id' => $newJobId]), 'data-exchange-job');
			}
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RejectionResubmissionAction($objectService, $session);
	}//end makeAction()

	/**
	 * The rejection as OpenRegister's LifecycleActionListener hands it over.
	 *
	 * @return array<string,mixed>
	 */
	private function rejection(): array {
		return [
			'id' => 'rej-1',
			'sourceKind' => 'learner-profile',
			'learnerProfileId' => 'lp-1',
			'dataExchangeJobId' => 'job-orig',
			'tenant_id' => 'tenant-a',
			'status' => 'resubmitted',
		];
	}//end rejection()

	/**
	 * OpenRegister's action registry refuses a handler without the interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, $this->makeAction(null));
	}//end testImplementsTheOpenRegisterActionInterface()

	/**
	 * Exactly one scoped job is created and its id ends up on the saved rejection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-resubmit-creates-exactly-one-scoped-job-and-stamps-the-link
	 */
	public function testResubmitCreatesScopedJobAndStampsLink(): void {
		$action = $this->makeAction(['id' => 'job-orig', 'target' => 'bron-rod', 'mappingProfileId' => 'profile-1']);

		$result = $action->execute($this->rejection(), [], [], RejectionResubmissionAction::class);

		self::assertCount(1, $this->savedObjects);
		$newJob = $this->savedObjects[0]['object'];
		self::assertSame('bron-rod', $newJob['target']);
		self::assertSame('profile-1', $newJob['mappingProfileId']);
		self::assertSame('learner-profile', $newJob['scope']['schema']);
		self::assertSame('lp-1', $newJob['scope']['filters']['id']);
		self::assertSame('queued', $newJob['lifecycle']);
		self::assertSame('actor-1', $newJob['requestedBy']);
		self::assertSame('tenant-a', $newJob['tenant_id']);

		self::assertSame('new-job-1', $result['resubmittedJobId']);
		self::assertSame('resubmitted', $result['status']);
	}//end testResubmitCreatesScopedJobAndStampsLink()

	/**
	 * A caller-supplied resubmittedJobId is overwritten with the real new job id.
	 *
	 * @return void
	 */
	public function testCallerSuppliedResubmittedJobIdIsOverwritten(): void {
		$action = $this->makeAction(['id' => 'job-orig', 'target' => 'bron-rod', 'mappingProfileId' => 'profile-1']);

		$object = $this->rejection();
		$object['resubmittedJobId'] = 'attacker-supplied-id';

		self::assertSame('new-job-1', $action->execute($object, [], [], RejectionResubmissionAction::class)['resubmittedJobId']);
	}//end testCallerSuppliedResubmittedJobIdIsOverwritten()

	/**
	 * A job save that yields no usable id throws, so the rejection is not saved as resubmitted.
	 *
	 * @return void
	 */
	public function testJobSaveWithoutIdThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction(['id' => 'job-orig', 'target' => 'bron-rod'], newJobId: null)
			->execute($this->rejection(), [], [], RejectionResubmissionAction::class);
	}//end testJobSaveWithoutIdThrows()

	/**
	 * An unresolvable originating job throws before anything is created.
	 *
	 * @return void
	 */
	public function testUnresolvableOriginalJobThrows(): void {
		try {
			$this->makeAction(null)->execute($this->rejection(), [], [], RejectionResubmissionAction::class);
			self::fail('Expected a RuntimeException.');
		} catch (RuntimeException $e) {
			self::assertCount(0, $this->savedObjects);
		}
	}//end testUnresolvableOriginalJobThrows()

	/**
	 * Without a session user the action throws: requestedBy must be a real person.
	 *
	 * @return void
	 */
	public function testNoSessionUserThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction(['id' => 'job-orig', 'target' => 'bron-rod'], uid: null)
			->execute($this->rejection(), [], [], RejectionResubmissionAction::class);
	}//end testNoSessionUserThrows()
}//end class
