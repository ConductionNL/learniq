<?php

/**
 * Learniq LearnerMergeHandler and LearnerMergeGuard unit tests.
 *
 * Covers learniq#950: firing `merge` on a LearnerProfile must move the
 * learner-owned records (enrolments, grades, attendance, credentials,
 * portfolio entries and the rest) from the merged account to the surviving
 * one, keep `mergedInto` set, and refuse a merge whose target is missing,
 * inactive, the profile itself, or holds a conflicting open enrolment.
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
 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Lifecycle\LearnerMergeGuard;
use OCA\Learniq\Listener\LearnerMergeHandler;
use OCA\Learniq\Service\LearnerMergeService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the learner merge: records move, mergedInto stays, bad merges are refused.
 */
class LearnerMergeHandlerTest extends TestCase {

	private const MERGED_UUID = '11111111-1111-4111-8111-111111111111';
	private const SURVIVOR_UUID = '22222222-2222-4222-8222-222222222222';

	/**
	 * In-memory store, keyed by schema slug, of the rows findAll() filters over.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Seed the store with two profiles for one person, each with its own history.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->store = [
			'learner-profile' => [
				['id' => self::MERGED_UUID, 'ncUserId' => 'jan-old', 'lifecycle' => 'merged', 'mergedInto' => self::SURVIVOR_UUID],
				['id' => self::SURVIVOR_UUID, 'ncUserId' => 'jan', 'lifecycle' => 'active'],
			],
			'enrolment' => [
				['id' => 'enr-old', 'learnerId' => 'jan-old', 'learnerRef' => self::MERGED_UUID, 'courseId' => 'course-a', 'lifecycle' => 'completed'],
				['id' => 'enr-new', 'learnerId' => 'jan', 'learnerRef' => self::SURVIVOR_UUID, 'courseId' => 'course-b', 'lifecycle' => 'active'],
			],
			'grade-entry' => [
				['id' => 'grade-old', 'learnerId' => 'jan-old', 'learnerRef' => self::MERGED_UUID, 'value' => 7.5],
			],
			'attendance-record' => [
				['id' => 'att-old', 'learnerId' => 'jan-old', 'learnerRef' => self::MERGED_UUID],
			],
			'credential' => [
				['id' => 'cred-old', 'learnerId' => self::MERGED_UUID],
			],
			'portfolio-entry' => [
				['id' => 'pe-old', 'learnerId' => 'jan-old'],
			],
			'assessment-result' => [
				['id' => 'ar-other', 'learnerId' => 'someone-else'],
			],
		];

	}//end setUp()

	/**
	 * An ObjectService double whose findAll() really filters the store by the
	 * requested schema and `filters`, so a query on the wrong field finds nothing.
	 *
	 * @return ObjectService
	 */
	private function makeObjectService(): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$rows = $this->store[(string)($config['schema'] ?? '')] ?? [];
				$filters = $config['filters'] ?? [];
				$hits = [];
				foreach ($rows as $row) {
					$match = true;
					foreach ($filters as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$hits[] = OrEntityFactory::make($row, (string)$config['schema']);
					}
				}

				return $hits;
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				foreach ($this->store[(string)$schema] ?? [] as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->saved[] = ['schema' => (string)$schema, 'object' => $data];
				return OrEntityFactory::make($data, (string)$schema);
			}
		);

		return $objectService;
	}//end makeObjectService()

	/**
	 * Build the merge transition event for the merged profile.
	 *
	 * @param array<string, mixed> $profile The merged profile payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(array $profile): ObjectTransitionedEvent {
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make($profile, 'learner-profile'));
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('learner-profile');
		$event->method('getFrom')->willReturn('active');
		$event->method('getTo')->willReturn('merged');

		return $event;
	}//end makeEvent()

	/**
	 * The saved payload for a record id, or null when it was not saved.
	 *
	 * @param string $id Record id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function savedRecord(string $id): ?array {
		foreach ($this->saved as $save) {
			if (($save['object']['id'] ?? null) === $id) {
				return $save['object'];
			}
		}

		return null;
	}//end savedRecord()

	/**
	 * Firing merge moves every learner-owned record to the surviving account,
	 * rewriting both the Nextcloud user id and the LearnerProfile reference.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function testMergeMovesRecordsToTheSurvivingAccount(): void {
		$objectService = $this->makeObjectService();
		$handler = new LearnerMergeHandler(new LearnerMergeService($objectService, new NullLogger()), new NullLogger());

		$handler->handle($this->makeEvent($this->store['learner-profile'][0]));

		$enrolment = $this->savedRecord('enr-old');
		$this->assertNotNull($enrolment, 'the merged account enrolment must be moved');
		$this->assertSame('jan', $enrolment['learnerId']);
		$this->assertSame(self::SURVIVOR_UUID, $enrolment['learnerRef']);

		$grade = $this->savedRecord('grade-old');
		$this->assertNotNull($grade, 'the merged account grade must be moved');
		$this->assertSame('jan', $grade['learnerId']);
		$this->assertSame(self::SURVIVOR_UUID, $grade['learnerRef']);

		$this->assertSame('jan', $this->savedRecord('att-old')['learnerId'] ?? null);
		$this->assertSame(self::SURVIVOR_UUID, $this->savedRecord('cred-old')['learnerId'] ?? null);
		$this->assertSame('jan', $this->savedRecord('pe-old')['learnerId'] ?? null);

		// Records of the surviving account and of other people stay untouched.
		$this->assertNull($this->savedRecord('enr-new'));
		$this->assertNull($this->savedRecord('ar-other'));

	}//end testMergeMovesRecordsToTheSurvivingAccount()

	/**
	 * The merge never rewrites a LearnerProfile, so `mergedInto` stays set on
	 * the merged profile and the audit link survives.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function testMergeKeepsMergedIntoSet(): void {
		$handler = new LearnerMergeHandler(new LearnerMergeService($this->makeObjectService(), new NullLogger()), new NullLogger());

		$handler->handle($this->makeEvent($this->store['learner-profile'][0]));

		foreach ($this->saved as $save) {
			$this->assertNotSame('learner-profile', $save['schema'], 'the merge must not rewrite a profile');
		}

		$this->assertNotEmpty($this->saved);

	}//end testMergeKeepsMergedIntoSet()

	/**
	 * Other transitions and other schemas are ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function testOtherTransitionsAreIgnored(): void {
		$handler = new LearnerMergeHandler(new LearnerMergeService($this->makeObjectService(), new NullLogger()), new NullLogger());

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make($this->store['learner-profile'][0], 'learner-profile'));
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('learner-profile');
		$event->method('getTo')->willReturn('deleted');

		$handler->handle($event);

		$this->assertSame([], $this->saved);

	}//end testOtherTransitionsAreIgnored()

	/**
	 * The guard lets a merge into an active, different profile through.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function testGuardAllowsMergeIntoActiveProfile(): void {
		$guard = new LearnerMergeGuard(new LearnerMergeService($this->makeObjectService(), new NullLogger()), new NullLogger());
		$object = ['id' => self::MERGED_UUID, 'ncUserId' => 'jan-old', 'mergedInto' => self::SURVIVOR_UUID, 'lifecycle' => 'merged'];

		$this->assertTrue($guard->check($object, 'merge', 'admin')->isAllowed());

	}//end testGuardAllowsMergeIntoActiveProfile()

	/**
	 * The guard refuses a merge without a target, into itself, into a missing
	 * profile, or into a profile that is not active.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function testGuardRefusesInvalidTargets(): void {
		$this->store['learner-profile'][] = ['id' => 'gone', 'ncUserId' => 'x', 'lifecycle' => 'deleted'];
		$guard = new LearnerMergeGuard(new LearnerMergeService($this->makeObjectService(), new NullLogger()), new NullLogger());

		foreach ([null, self::MERGED_UUID, 'does-not-exist', 'gone'] as $target) {
			$object = ['id' => self::MERGED_UUID, 'ncUserId' => 'jan-old', 'mergedInto' => $target, 'lifecycle' => 'merged'];
			$verdict = $guard->check($object, 'merge', 'admin');
			$this->assertFalse($verdict->isAllowed(), 'merge into '.var_export($target, true).' must be refused');
			$this->assertStringStartsWith('This merge is refused: ', (string)$verdict->getMessage());
		}

	}//end testGuardRefusesInvalidTargets()

	/**
	 * The guard refuses when both accounts hold an open enrolment in the same course.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function testGuardRefusesConflictingOpenEnrolments(): void {
		$this->store['enrolment'][] = ['id' => 'enr-clash', 'learnerId' => 'jan-old', 'learnerRef' => self::MERGED_UUID, 'courseId' => 'course-b', 'lifecycle' => 'pending'];
		$guard = new LearnerMergeGuard(new LearnerMergeService($this->makeObjectService(), new NullLogger()), new NullLogger());
		$object = ['id' => self::MERGED_UUID, 'ncUserId' => 'jan-old', 'mergedInto' => self::SURVIVOR_UUID, 'lifecycle' => 'merged'];
		$verdict = $guard->check($object, 'merge', 'admin');

		$this->assertFalse($verdict->isAllowed());
		$this->assertStringContainsString('open enrolment', (string)$verdict->getMessage());

	}//end testGuardRefusesConflictingOpenEnrolments()

	/**
	 * The register wires the guard onto the merge transition.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function testRegisterWiresTheGuardOnMerge(): void {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		$merge = $register['components']['schemas']['LearnerProfile']['x-openregister-lifecycle']['transitions']['merge'];

		$this->assertSame(LearnerMergeGuard::class, $merge['requires'] ?? null);

	}//end testRegisterWiresTheGuardOnMerge()
}//end class
