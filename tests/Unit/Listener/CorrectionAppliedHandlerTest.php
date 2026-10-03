<?php

/**
 * After a grade in a locked period is published again on an approved
 * correction, the correction is applied and the grade names it.
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\CorrectionAppliedHandler;
use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * CorrectionAppliedHandler on the real ObjectTransitionedEvent.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */
class CorrectionAppliedHandlerTest extends TestCase {

	/**
	 * The published grade entry.
	 *
	 * @var array<string, mixed>
	 */
	private const ENTRY = ['id' => 'entry-1', 'value' => 6.5, 'lifecycle' => 'published'];

	/**
	 * Teacher A's request, approved by principal B.
	 *
	 * @var array<string, mixed>
	 */
	private const APPROVED = [
		'id'            => 'dcr-1',
		'gradeEntryId'  => 'entry-1',
		'proposedValue' => 6.5,
		'currentValue'  => 5.5,
		'reason'        => 'A page of the exam was not counted.',
		'requestedBy'   => 'teacher-a',
		'decidedBy'     => 'principal-b',
		'lifecycle'     => 'approved',
	];

	/**
	 * Saves the handler made: schema, object, uuid and whether the caller's rights applied.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>, uuid: mixed, rbac: bool}>
	 */
	private array $saved = [];

	/**
	 * The handler over a store holding the given correction requests.
	 *
	 * @param array<int, array<string, mixed>> $corrections Rows in the store.
	 *
	 * @return CorrectionAppliedHandler
	 */
	private function handler(array $corrections, bool $saveFails = false): CorrectionAppliedHandler {
		$this->saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => OrEntityFactory::makeMany(array_values(
				array_filter(
					$corrections,
					static fn (array $row): bool => $config['filters']['schema'] === 'data-correction-request'
						&& $row['gradeEntryId'] === $config['filters']['gradeEntryId']
						&& $row['lifecycle'] === $config['filters']['lifecycle']
				)
			), 'data-correction-request')
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, $uuid = null, bool $_rbac = true) use ($saveFails): ObjectEntity {
				if ($saveFails === true) {
					throw new \RuntimeException('database gone');
				}

				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->saved[] = ['schema' => (string)$schema, 'object' => $data, 'uuid' => $uuid, 'rbac' => $_rbac];
				return OrEntityFactory::make($data, (string)$schema);
			}
		);

		return new CorrectionAppliedHandler(
			schemas: TransitionScope::resolver(),
			approvals: new CorrectionApprovals(objects: $objects),
			objects: $objects,
			logger: new NullLogger()
		);
	}//end handler()

	/**
	 * The transition event for a grade entry.
	 *
	 * @param string $action The transition.
	 * @param string $userId Who published.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private static function published(string $action = 'republish', string $userId = 'teacher-a'): ObjectTransitionedEvent {
		return new ObjectTransitionedEvent(OrEntityFactory::make(self::ENTRY, 'grade-entry'), $action, 'revised', 'published', $userId, 'learniq', 'grade-entry');
	}//end published()

	/**
	 * Teacher A republishes on principal B's approval: the request moves to
	 * applied, as the system, since a teacher may not update a request. The
	 * handler sends no appliedBy/appliedAt: both are readOnly, and the `apply`
	 * transition's stamp action writes them on the save path (live pass D10;
	 * CorrectionAppliedThroughApplyTransitionTest saves through a store that
	 * enforces that). The entry link is the republish's own action, so the
	 * handler writes nothing to the grade entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function testARepublishOnAnApprovalAppliesIt(): void {
		$this->handler(corrections: [self::APPROVED])->handle(self::published());

		self::assertCount(1, $this->saved);
		[$request] = $this->saved;
		self::assertSame('data-correction-request', $request['schema']);
		self::assertSame('dcr-1', $request['uuid']);
		self::assertSame('applied', $request['object']['lifecycle']);
		self::assertArrayNotHasKey('appliedBy', $request['object']);
		self::assertArrayNotHasKey('appliedAt', $request['object']);
		self::assertSame('principal-b', $request['object']['decidedBy']);
		self::assertFalse($request['rbac']);
	}//end testARepublishOnAnApprovalAppliesIt()

	/**
	 * Nothing is written without a covering approval, for another schema, or
	 * for a move that does not end in published.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutACoveringApproval(): void {
		$this->handler(corrections: [])->handle(self::published());
		self::assertSame([], $this->saved, 'no request');

		$this->handler(corrections: [self::APPROVED])->handle(self::published(userId: 'principal-b'));
		self::assertSame([], $this->saved, 'the approver published');

		$revise = new ObjectTransitionedEvent(OrEntityFactory::make(self::ENTRY, 'grade-entry'), 'revise', 'published', 'revised', 'teacher-a', 'learniq', 'grade-entry');
		$this->handler(corrections: [self::APPROVED])->handle($revise);
		self::assertSame([], $this->saved, 'a revise');

		$other = new ObjectTransitionedEvent(OrEntityFactory::make(self::ENTRY, 'final-grade'), 'publish', 'concept', 'published', 'teacher-a', 'learniq', 'final-grade');
		$this->handler(corrections: [self::APPROVED])->handle($other);
		self::assertSame([], $this->saved, 'another schema');

		$this->handler(corrections: [self::APPROVED], saveFails: true)->handle(self::published());
		self::assertSame([], $this->saved, 'a failed save is logged, the publish stands');
	}//end testNothingIsWrittenWithoutACoveringApproval()
}//end class
