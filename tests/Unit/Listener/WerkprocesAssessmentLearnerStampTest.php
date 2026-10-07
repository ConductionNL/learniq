<?php

/**
 * Learniq WerkprocesAssessmentLearnerStamp unit tests.
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\WerkprocesAssessmentLearnerStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for WerkprocesAssessmentLearnerStamp::handle().
 */
class WerkprocesAssessmentLearnerStampTest extends TestCase {
	use RegisterSchemaPayloads;

	private const PLACEMENT = '6b1f0a52-3c1d-4f0e-9a51-1d2b3c4d5e6f';

	private const TENANT = '0f8e7d6c-5b4a-4392-8170-6e5d4c3b2a19';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the listener over the fake store.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return WerkprocesAssessmentLearnerStamp
	 */
	private function makeStamp(string $slug = 'werkproces-assessment'): WerkprocesAssessmentLearnerStamp {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['bpv-placement'] = [
			['id' => self::PLACEMENT, 'learnerId' => 'jan', 'learnerRef' => 'lp-jan'],
			['id' => 'bp-nobody', 'learnerRef' => 'lp-x'],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new WerkprocesAssessmentLearnerStamp(
			schemaResolver: $schemaResolver,
			objectService: $objectService,
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * An assessment the way an assessor writes it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function assessment(array $overrides = []): array {
		return array_merge(
			[
				'bpvPlacementId' => self::PLACEMENT,
				'curriculumPlanId' => '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c8d',
				'componentId' => 'pvb-1',
				'kwalificatiedossierCode' => '25180',
				'coreTaskCode' => 'B1-K1',
				'werkprocesCode' => 'B1-K1-W1',
				'werkprocesLabel' => 'Bereidt de werkzaamheden voor',
				'assessorId' => '9c8b7a6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d',
				'assessment' => 'competent',
				'tenant_id' => self::TENANT,
				'lifecycle' => 'draft',
			],
			$overrides
		);
	}//end assessment()

	/**
	 * A created assessment gets the placement's student, and the stamped
	 * payload fits the shipped schema.
	 *
	 * @return void
	 */
	public function testACreateGetsTheStudentOfThePlacement(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::assessment(), 'werkproces-assessment'));
		$this->makeStamp()->handle($event);

		self::assertSame('jan', $event->getModifiedData()['learnerId']);
		self::assertFalse($event->isPropagationStopped());
		self::assertNull(self::schemaError('werkproces-assessment', array_merge(self::assessment(), $event->getModifiedData())));
	}//end testACreateGetsTheStudentOfThePlacement()

	/**
	 * A learnerId sent by the client is replaced by the derived one.
	 *
	 * @return void
	 */
	public function testAForgedStudentIsReplaced(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::assessment(['learnerId' => 'piet']), 'werkproces-assessment'));
		$this->makeStamp()->handle($event);

		self::assertSame('jan', $event->getModifiedData()['learnerId']);
	}//end testAForgedStudentIsReplaced()

	/**
	 * An update that moves the assessment to another placement takes that
	 * placement's student; a placement naming nobody gives null, and the
	 * write still goes through.
	 *
	 * @return void
	 */
	public function testAPlacementNamingNobodyGivesNull(): void {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(self::assessment(['bpvPlacementId' => 'bp-nobody', 'learnerId' => 'jan']), 'werkproces-assessment'),
			OrEntityFactory::make(self::assessment(['learnerId' => 'jan']), 'werkproces-assessment')
		);
		$this->makeStamp()->handle($event);

		self::assertArrayHasKey('learnerId', $event->getModifiedData());
		self::assertNull($event->getModifiedData()['learnerId']);
		self::assertFalse($event->isPropagationStopped());
		self::assertNull(self::schemaError('werkproces-assessment', array_merge(self::assessment(), $event->getModifiedData())));
	}//end testAPlacementNamingNobodyGivesNull()

	/**
	 * When the placement cannot be read, an update on the same placement
	 * keeps the stored student and a create gets null.
	 *
	 * @return void
	 */
	public function testAFailedLookupKeepsTheStoredStudentOnlyOnTheSamePlacement(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';

		$update = new ObjectUpdatingEvent(
			OrEntityFactory::make(self::assessment(['lifecycle' => 'submitted']), 'werkproces-assessment'),
			OrEntityFactory::make(self::assessment(['learnerId' => 'jan']), 'werkproces-assessment')
		);
		$stamp->handle($update);
		self::assertSame('jan', $update->getModifiedData()['learnerId']);

		$create = new ObjectCreatingEvent(OrEntityFactory::make(self::assessment(['learnerId' => 'piet']), 'werkproces-assessment'));
		$stamp->handle($create);
		self::assertNull($create->getModifiedData()['learnerId']);
	}//end testAFailedLookupKeepsTheStoredStudentOnlyOnTheSamePlacement()

	/**
	 * Another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['bpvPlacementId' => self::PLACEMENT], 'bpv-hour-week'));
		$this->makeStamp(slug: 'bpv-hour-week')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

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

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . WerkprocesAssessmentLearnerStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . WerkprocesAssessmentLearnerStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()

	/**
	 * The shipped read rule lets a student read their own confirmed
	 * assessment and nothing else: not a draft, not another student's.
	 *
	 * @return void
	 */
	public function testTheReadRuleIsTheStudentsOwnConfirmedAssessment(): void {
		$store = new RegisterFaithfulStore();
		$store->actingUser = 'jan';
		$store->callerGroups = [];

		self::assertTrue($store->callerMay('werkproces-assessment', 'read', ['learnerId' => 'jan', 'lifecycle' => 'confirmed']));
		self::assertFalse($store->callerMay('werkproces-assessment', 'read', ['learnerId' => 'jan', 'lifecycle' => 'draft']));
		self::assertFalse($store->callerMay('werkproces-assessment', 'read', ['learnerId' => 'jan', 'lifecycle' => 'submitted']));
		self::assertFalse($store->callerMay('werkproces-assessment', 'read', ['learnerId' => 'piet', 'lifecycle' => 'confirmed']));
		self::assertFalse($store->callerMay('werkproces-assessment', 'update', ['learnerId' => 'jan', 'lifecycle' => 'confirmed']));
		self::assertFalse($store->callerMay('werkproces-assessment', 'create', ['learnerId' => 'jan']));

		$store->callerGroups = ['instructors'];
		self::assertTrue($store->callerMay('werkproces-assessment', 'read', ['learnerId' => 'jan', 'lifecycle' => 'draft']));
		self::assertTrue($store->callerMay('werkproces-assessment', 'update', ['learnerId' => 'jan', 'lifecycle' => 'draft']));
	}//end testTheReadRuleIsTheStudentsOwnConfirmedAssessment()

	/**
	 * An event that is not a create or update is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$event = $this->createMock(\OCP\EventDispatcher\Event::class);
		$event->expects(self::never())->method('isPropagationStopped');

		$this->makeStamp()->handle($event);
	}//end testAnotherEventIsIgnored()

	/**
	 * When the schema of the entity cannot be resolved, the write is left alone.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsLeftAlone(): void {
		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('schema gone'));
		$stamp = new WerkprocesAssessmentLearnerStamp(
			schemaResolver: $schemaResolver,
			objectService: $this->createMock(ObjectService::class),
			logger: new NullLogger(),
		);
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::assessment(['learnerId' => 'piet']), 'werkproces-assessment'));

		$stamp->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnUnresolvableSchemaIsLeftAlone()

	/**
	 * No placement, or a placement that does not exist, gives null.
	 *
	 * @return void
	 */
	public function testAMissingPlacementGivesNull(): void {
		$stamp = $this->makeStamp();

		self::assertNull($stamp->learnerOfPlacement(''));
		self::assertNull($stamp->learnerOfPlacement('3c2b1a09-8f7e-4d6c-9b5a-4f3e2d1c0b9a'));

		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::assessment(['bpvPlacementId' => null, 'learnerId' => 'piet']), 'werkproces-assessment'));
		$stamp->handle($event);
		self::assertNull($event->getModifiedData()['learnerId']);
	}//end testAMissingPlacementGivesNull()

	/**
	 * The lookup reads array rows and entities alike, and skips a row that
	 * is not the asked placement or cannot be read as a row.
	 *
	 * @return void
	 */
	public function testTheLookupSkipsRowsThatAreNotThePlacement(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				'not a row',
				new \ArrayObject(['id' => self::PLACEMENT]),
				['id' => 'bp-other', 'learnerId' => 'piet'],
				['id' => self::PLACEMENT, 'learnerId' => 'jan'],
			]
		);
		$stamp = new WerkprocesAssessmentLearnerStamp(
			schemaResolver: $this->createMock(ListenerSchemaResolver::class),
			objectService: $objectService,
			logger: new NullLogger(),
		);

		self::assertSame('jan', $stamp->learnerOfPlacement(self::PLACEMENT));
	}//end testTheLookupSkipsRowsThatAreNotThePlacement()

	/**
	 * After a failed lookup, an update that moves the placement, or whose
	 * stored row names nobody, gets null (fail closed).
	 *
	 * @return void
	 */
	public function testAFailedLookupOnAMovedOrEmptyRowGivesNull(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';

		$moved = new ObjectUpdatingEvent(
			OrEntityFactory::make(self::assessment(['bpvPlacementId' => 'bp-nobody']), 'werkproces-assessment'),
			OrEntityFactory::make(self::assessment(['learnerId' => 'jan']), 'werkproces-assessment')
		);
		$stamp->handle($moved);
		self::assertNull($moved->getModifiedData()['learnerId']);

		$empty = new ObjectUpdatingEvent(
			OrEntityFactory::make(self::assessment(['lifecycle' => 'submitted']), 'werkproces-assessment'),
			OrEntityFactory::make(self::assessment(['learnerId' => '']), 'werkproces-assessment')
		);
		$stamp->handle($empty);
		self::assertNull($empty->getModifiedData()['learnerId']);
	}//end testAFailedLookupOnAMovedOrEmptyRowGivesNull()
}//end class
