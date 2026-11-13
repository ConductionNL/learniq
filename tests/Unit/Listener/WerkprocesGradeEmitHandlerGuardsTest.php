<?php

/**
 * Learniq WerkprocesGradeEmitHandler guard and payload tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\WerkprocesGradeEmitHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each reason a confirmed assessment emits nothing, as logged, and the full GradeEntry it writes.
 */
class WerkprocesGradeEmitHandlerGuardsTest extends TestCase {

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var list<array<string, mixed>> */
	private array $saved = [];

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->saved  = [];
	}//end setUp()

	/**
	 * The handler, reading the given placement, plan and existing grade entries.
	 *
	 * @param array<string, mixed>|null  $placement The BPV placement, if found.
	 * @param array<string, mixed>|null  $plan      The curriculum plan, if found.
	 * @param list<array<string, mixed>> $existing  The existing grade entries.
	 *
	 * @return WerkprocesGradeEmitHandler
	 */
	private function handler(?array $placement, ?array $plan = null, array $existing = []): WerkprocesGradeEmitHandler {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($placement, $plan, $existing): array {
				return match ($config['filters']['schema']) {
					'bpv-placement'   => ($placement === null ? [] : [$placement]),
					'curriculum-plan' => ($plan === null ? [] : [$plan]),
					'grade-entry'     => $existing,
					default           => [],
				};
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$this->saved[] = ['register' => (string)$register, 'schema' => (string)$schema, 'object' => $object];
				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);

		return new WerkprocesGradeEmitHandler($objects, $this->logger, TransitionScope::resolver());
	}//end handler()

	/**
	 * A confirmed transition of the given assessment.
	 *
	 * @param array<string, mixed> $assessment The assessment data.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function confirmed(array $assessment): ObjectTransitionedEvent {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($assessment);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($object);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('werkproces-assessment');
		$event->method('getTo')->willReturn('confirmed');
		$event->method('getFrom')->willReturn('submitted');

		return $event;
	}//end confirmed()

	/**
	 * An assessment missing a reference is logged by its uuid and emits nothing.
	 *
	 * @return void
	 */
	public function testAMissingReferenceIsLoggedAndEmitsNothing(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'[WerkprocesGradeEmitHandler] Confirmed WerkprocesAssessment {id} missing bpvPlacementId/'
			.'curriculumPlanId/componentId — cannot emit GradeEntry.',
			['id' => 'uuid-1']
		);

		$this->handler(['learnerId' => 'l-1'])->handle(
			$this->confirmed(['uuid' => 'uuid-1', 'bpvPlacementId' => 'p-1', 'curriculumPlanId' => 'plan-1', 'assessment' => 'competent'])
		);

		$this->assertSame([], $this->saved);
	}//end testAMissingReferenceIsLoggedAndEmitsNothing()

	/**
	 * An unrecognised beoordeling is logged with its value and emits nothing.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedBeoordelingIsLoggedAndEmitsNothing(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'[WerkprocesGradeEmitHandler] Confirmed WerkprocesAssessment {id} has unrecognised beoordeling '
			.'"{b}" — cannot map to a GradeEntry value.',
			['id' => 'wpa-1', 'b' => 'excellent']
		);

		$this->handler(['learnerId' => 'l-1'])->handle(
			$this->confirmed(['id' => 'wpa-1', 'bpvPlacementId' => 'p-1', 'curriculumPlanId' => 'plan-1', 'componentId' => 'c-1', 'assessment' => 'excellent'])
		);

		$this->assertSame([], $this->saved);
	}//end testAnUnrecognisedBeoordelingIsLoggedAndEmitsNothing()

	/**
	 * A missing placement and a placement without a learner are each logged by the placement id.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutALearnerIsLoggedAndEmitsNothing(): void {
		$assessment = ['id' => 'wpa-1', 'bpvPlacementId' => 'p-1', 'curriculumPlanId' => 'plan-1', 'componentId' => 'c-1', 'assessment' => 'competent'];
		$messages   = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		$this->handler(null)->handle($this->confirmed($assessment));
		$this->handler(['tenant_id' => 't-1'])->handle($this->confirmed($assessment));

		$this->assertSame(
			[
				['[WerkprocesGradeEmitHandler] BpvPlacement {id} referenced by WerkprocesAssessment not found.', ['id' => 'p-1']],
				['[WerkprocesGradeEmitHandler] BpvPlacement {id} has no learnerId — cannot emit GradeEntry.', ['id' => 'p-1']],
			],
			$messages
		);
		$this->assertSame([], $this->saved);
	}//end testAPlacementWithoutALearnerIsLoggedAndEmitsNothing()

	/**
	 * The GradeEntry carries every field, in order, without a grade scale when the plan is gone, and its creation is logged.
	 *
	 * @return void
	 */
	public function testTheGradeEntryCarriesEveryField(): void {
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->once())->method('info')->with(
			'[WerkprocesGradeEmitHandler] WerkprocesAssessment {aid} confirmed → GradeEntry {kind} for learner '
			.'{learner}, component {component}.',
			['aid' => 'uuid-9', 'kind' => 'created', 'learner' => 'l-1', 'component' => 'c-1']
		);

		$this->handler(['learnerId' => 'l-1'])->handle(
			$this->confirmed(['uuid' => 'uuid-9', 'bpvPlacementId' => 'p-1', 'curriculumPlanId' => 'plan-1', 'componentId' => 'c-1', 'assessment' => 'nog-niet-competent'])
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame(['learniq', 'grade-entry'], [$this->saved[0]['register'], $this->saved[0]['schema']]);
		$entry = $this->saved[0]['object'];
		$this->assertSame(
			['learnerId', 'curriculumPlanId', 'componentId', 'sourceKind', 'value', 'gradeScaleId', 'grader', 'gradedAt', 'tenant_id', 'lifecycle'],
			array_keys($entry)
		);
		$this->assertSame(0.0, $entry['value']);
		$this->assertSame('', $entry['gradeScaleId']);
		$this->assertSame('praktijkopleider', $entry['grader']);
		$this->assertSame('', $entry['tenant_id']);
		$this->assertSame('concept', $entry['lifecycle']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $entry['gradedAt']);
	}//end testTheGradeEntryCarriesEveryField()

	/**
	 * Updating keeps the existing entry's other fields and its lifecycle, and is logged as an update.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsTheExistingFieldsAndLifecycle(): void {
		$this->logger->expects($this->once())->method('info')->with(
			$this->anything(),
			$this->callback(static fn (array $context): bool => $context['kind'] === 'updated')
		);

		$this->handler(
			['learnerId' => 'l-1', 'tenant_id' => 't-1'],
			['gradeScaleId' => 'scale-1'],
			[['id' => 'ge-1', 'learnerId' => 'l-1', 'curriculumPlanId' => 'plan-1', 'componentId' => 'c-1', 'tenant_id' => 't-1', 'lifecycle' => 'published', 'note' => 'kept']]
		)->handle(
			$this->confirmed(['id' => 'wpa-1', 'bpvPlacementId' => 'p-1', 'curriculumPlanId' => 'plan-1', 'componentId' => 'c-1', 'assessment' => 'competent'])
		);

		$entry = $this->saved[0]['object'];
		$this->assertSame('ge-1', $entry['id']);
		$this->assertSame('kept', $entry['note']);
		$this->assertSame('published', $entry['lifecycle']);
		$this->assertSame('scale-1', $entry['gradeScaleId']);
		$this->assertSame(1.0, $entry['value']);
	}//end testAnUpdateKeepsTheExistingFieldsAndLifecycle()
}//end class
