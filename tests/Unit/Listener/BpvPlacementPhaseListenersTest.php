<?php

/**
 * Learniq BPV placement phase listener tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The phase stamp on a placement save and the re-save when a visit changes,
 * against real OpenRegister events and a store with the live filter semantics.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Bpv\PlacementPhase;
use OCA\Learniq\Listener\BpvPlacementPhaseStamp;
use OCA\Learniq\Listener\BpvVisitPhaseRollup;
use OCA\Learniq\Listener\WrittenObject;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Support\SerialisedRow;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(BpvPlacementPhaseStamp::class)]
#[CoversClass(BpvVisitPhaseRollup::class)]
#[UsesClass(PlacementPhase::class)]
#[UsesClass(WrittenObject::class)]
#[UsesClass(SerialisedRow::class)]
final class BpvPlacementPhaseListenersTest extends TestCase {

	private const PLACEMENT = 'ee030020-0000-4000-8000-000000000011';

	/**
	 * Rows by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * What was saved.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>, uuid: ?string}>
	 */
	private array $saved = [];

	/**
	 * Milan's placement in agreement: company, POK not yet active.
	 *
	 * @param array<string, mixed> $extra The fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private static function placement(array $extra=[]): array {
		return $extra + [
			'learnerRef' => 'lp-milan',
			'trainingCompanyName' => 'Bakker Techniek BV',
			'lifecycle' => 'confirmed',
			'periodFrom' => '2026-09-01',
			'periodTo' => '2027-01-29',
		];

	}//end placement()

	/**
	 * An ObjectService over the rows.
	 *
	 * @param bool $readsThrow Whether every read fails.
	 *
	 * @return ObjectService
	 */
	private function objects(bool $readsThrow=false): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(function (array $config=[]) use ($readsThrow): array {
			if ($readsThrow === true) {
				throw new RuntimeException('database gone');
			}

			$filters = $config['filters'];
			$rows    = ($this->rows[$filters['schema']] ?? []);
			unset($filters['register'], $filters['schema']);

			return array_values(array_filter($rows, static function (array $row) use ($filters, $config): bool {
				if (isset($config['ids']) === true && in_array(($row['id'] ?? ''), $config['ids'], true) === false) {
					return false;
				}

				foreach ($filters as $key => $value) {
					if (($row[$key] ?? null) !== $value) {
						return false;
					}
				}

				return true;
			}));
		});
		$objects->method('saveObject')->willReturnCallback(function (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null): \OCA\OpenRegister\Db\ObjectEntity {
			$this->saved[] = ['schema' => (string) $schema, 'object' => $object, 'uuid' => $uuid];
			return OrEntityFactory::make($object, (string) $schema, 'learniq', $uuid);
		});

		return $objects;

	}//end objects()

	/**
	 * The stamp, on 15 September 2026, with Ruud signed in.
	 *
	 * @param string $slug       The schema the resolver reports.
	 * @param bool   $readsThrow Whether every read fails.
	 *
	 * @return BpvPlacementPhaseStamp
	 */
	private function stamp(string $slug='bpv-placement', bool $readsThrow=false): BpvPlacementPhaseStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn((int) strtotime('2026-09-15T09:30:00Z'));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ruud');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BpvPlacementPhaseStamp($resolver, $this->objects($readsThrow), $clock, $session, new NullLogger());

	}//end stamp()

	/**
	 * An update of the stored placement with these changes.
	 *
	 * @param array<string, mixed> $stored  The stored placement.
	 * @param array<string, mixed> $changes The changes sent.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private static function update(array $stored, array $changes): ObjectUpdatingEvent {
		$entity = OrEntityFactory::make($stored, 'bpv-placement', 'learniq', self::PLACEMENT);
		$event  = new ObjectUpdatingEvent($entity, $entity);
		$event->setModifiedData($changes);

		return $event;

	}//end update()

	public function testTheLastPokSignatureMovesThePlacementToRunningWithoutADrag(): void {
		$event = self::update(self::placement(['phase' => 'agreement']), ['lifecycle' => 'active']);

		$this->stamp()->handle($event);

		$data = $event->getModifiedData();
		$this->assertSame('active', $data['lifecycle']);
		$this->assertSame('running', $data['phase']);
		$this->assertNull($data['phaseDueAt']);
		$this->assertSame('', $data['lateLabel']);

	}//end testTheLastPokSignatureMovesThePlacementToRunningWithoutADrag()

	public function testANewPlacementWithoutACompanyIsSearchingAndAValueSentForThePhaseIsReplaced(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::placement(['trainingCompanyName' => '', 'lifecycle' => 'proposed', 'phase' => 'done', 'lateLabel' => 'fake']), 'bpv-placement'));

		$this->stamp()->handle($event);

		$data = $event->getModifiedData();
		$this->assertSame(['searching', '2026-09-01', '2 weken te laat'], [$data['phase'], $data['phaseDueAt'], $data['lateLabel']]);

	}//end testANewPlacementWithoutACompanyIsSearchingAndAValueSentForThePhaseIsReplaced()

	public function testAManualMoveIsLoggedAndHoldsUntilAFactChanges(): void {
		$event = self::update(self::placement(), ['phaseOverride' => ['phase' => 'running', 'movedBy' => 'mallory']]);
		$this->stamp()->handle($event);
		$moved = $event->getModifiedData();

		$this->assertSame('running', $moved['phase']);
		$this->assertSame('ruud', $moved['phaseOverride']['movedBy']);
		$this->assertSame('2026-09-15T09:30:00+00:00', $moved['phaseOverride']['movedAt']);
		$this->assertSame([['phase' => 'running', 'movedBy' => 'ruud', 'movedAt' => '2026-09-15T09:30:00+00:00', 'from' => 'agreement']], $moved['phaseMoves']);

		$stored = array_merge(self::placement(), $moved);
		$again  = self::update($stored, ['workplaceAddress' => 'Industrieweg 4']);
		$this->stamp()->handle($again);
		$this->assertSame('running', $again->getModifiedData()['phase'], 'an unrelated change keeps the move');
		$this->assertCount(1, $again->getModifiedData()['phaseMoves']);

		$fact = self::update($stored, ['trainingCompanyName' => '']);
		$this->stamp()->handle($fact);
		$this->assertSame('searching', $fact->getModifiedData()['phase'], 'a fact change ends the move');
		$this->assertNull($fact->getModifiedData()['phaseOverride']);
		$this->assertCount(1, $fact->getModifiedData()['phaseMoves'], 'the log stays');

	}//end testAManualMoveIsLoggedAndHoldsUntilAFactChanges()

	public function testAnUnknownPhaseIsNotAMove(): void {
		$event = self::update(self::placement(), ['phaseOverride' => ['phase' => 'elsewhere']]);

		$this->stamp()->handle($event);

		$this->assertSame('agreement', $event->getModifiedData()['phase']);
		$this->assertNull($event->getModifiedData()['phaseOverride']);
		$this->assertSame([], $event->getModifiedData()['phaseMoves']);

	}//end testAnUnknownPhaseIsNotAMove()

	public function testAnAssessmentVisitIsReadFromTheStore(): void {
		$this->rows['bpv-visit'] = [['id' => 'v1', 'bpvPlacementId' => self::PLACEMENT, 'visitKind' => 'tussentijds-gesprek', 'lifecycle' => 'planned', 'plannedAt' => '2026-09-22']];
		$event = self::update(self::placement(['lifecycle' => 'active']), []);

		$this->stamp()->handle($event);

		$this->assertSame(['assessment', '2026-09-22'], [$event->getModifiedData()['phase'], $event->getModifiedData()['phaseDueAt']]);

	}//end testAnAssessmentVisitIsReadFromTheStore()

	public function testOtherSchemasAndUnreadableVisitsAreLeftAlone(): void {
		$other = self::update(self::placement(), ['x' => 1]);
		$this->stamp('bpv-hour-week')->handle($other);
		$this->assertSame(['x' => 1], $other->getModifiedData());

		$broken = self::update(self::placement(), ['x' => 1]);
		$this->stamp('bpv-placement', true)->handle($broken);
		$this->assertSame(['x' => 1], $broken->getModifiedData());

	}//end testOtherSchemasAndUnreadableVisitsAreLeftAlone()

	public function testAWrittenVisitSavesItsPlacementAgainWithoutTheStampedFields(): void {
		$this->rows['bpv-placement'] = [self::placement(['id' => self::PLACEMENT, 'phase' => 'running', 'phaseDueAt' => null, 'lateLabel' => '', '@self' => ['id' => self::PLACEMENT]])];
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturnCallback(static fn (object $entity): string => $entity->getSchema());
		$rollup = new BpvVisitPhaseRollup($resolver, $this->objects(), new NullLogger());
		$visit  = OrEntityFactory::make(['id' => 'v1', 'bpvPlacementId' => self::PLACEMENT, 'lifecycle' => 'planned'], 'bpv-visit');

		$rollup->handle(new ObjectUpdatedEvent($visit, $visit));

		$this->assertCount(1, $this->saved);
		$this->assertSame(['bpv-placement', self::PLACEMENT], [$this->saved[0]['schema'], $this->saved[0]['uuid']]);
		$this->assertSame(self::placement(), $this->saved[0]['object']);

		$this->saved = [];
		$placement   = OrEntityFactory::make(self::placement(['id' => self::PLACEMENT]), 'bpv-placement');
		$rollup->handle(new ObjectUpdatedEvent($placement, $placement));
		$orphan = OrEntityFactory::make(['id' => 'v2', 'lifecycle' => 'planned'], 'bpv-visit');
		$rollup->handle(new ObjectUpdatedEvent($orphan, $orphan));
		$this->assertSame([], $this->saved);

	}//end testAWrittenVisitSavesItsPlacementAgainWithoutTheStampedFields()
}//end class
