<?php

/**
 * Tests for the timetable source adapter: the planninq source (against a
 * verbatim copy of planninq's query event), the local Session source and the
 * resolver.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Timetabling\Source
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
 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling\Source;

if (class_exists('\\OCA\\Planninq\\Event\\TimetableSessionsQueryEvent') === false) {
	require_once __DIR__ . '/../../../Stubs/Planninq/Event/TimetableSessionsQueryEvent.php';
}

use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Planninq\Event\TimetableSessionsQueryEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The planninq source maps lessons and fails loudly; the resolver picks the source.
 */
class PlanninqTimetableSourceTest extends TestCase {

	/**
	 * Criteria of every dispatched query, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * A dispatcher that answers planninq's query like planninq's listener would.
	 *
	 * @param string $mode `answer`, `silent` or `refuse`.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(string $mode = 'answer'): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($mode): void {
				if (($event instanceof TimetableSessionsQueryEvent) === false || $mode === 'silent') {
					return;
				}

				$this->queries[] = ['sourceApp' => $event->getSourceApp()] + $event->getCriteria();
				if ($mode === 'refuse') {
					$event->setError('Name a cohortId, groupReference, teacherUserId or teacherReference to read a timetable.');
					return;
				}

				$event->setSessions(
					[
						['id' => 'p-1', 'externalRef' => 'zm-1', 'sourceSystem' => 'roster-zermelo', 'subject' => 'wi', 'title' => 'Wiskunde', 'startsAt' => '2026-09-28T09:00:00+02:00', 'endsAt' => '2026-09-28T09:50:00+02:00', 'groupReference' => '3a', 'cohortId' => 'c-1', 'teacherReference' => 'JAN', 'teacherUserId' => 'jan', 'roomReference' => 'A1.12', 'roomLabel' => null, 'status' => 'scheduled'],
						['id' => 'p-2', 'externalRef' => 'zm-2', 'sourceSystem' => 'roster-zermelo', 'subject' => 'ne', 'title' => '', 'startsAt' => '2026-09-28T10:00:00+02:00', 'endsAt' => '2026-09-28T10:50:00+02:00', 'groupReference' => '3a', 'cohortId' => 'c-1', 'teacherReference' => 'PIE', 'teacherUserId' => null, 'roomReference' => 'B2.04', 'roomLabel' => 'Lokaal B2.04', 'status' => 'cancelled'],
					]
				);
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * An app manager that says planninq is (not) installed.
	 *
	 * @param bool $installed Whether planninq is installed.
	 *
	 * @return IAppManager
	 */
	private function appManager(bool $installed): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $installed === true && $app === 'planninq'
		);

		return $appManager;
	}//end appManager()

	/**
	 * A cohort's lessons come from planninq in learniq's session shape.
	 *
	 * @return void
	 */
	public function testCohortLessonsComeFromPlanninq(): void {
		$source = new PlanninqTimetableSource($this->appManager(true), $this->dispatcher());

		$sessions = $source->sessionsForCohorts(['c-1', 'c-1', ''], '2026-09-28T00:00:00+02:00', null);

		$this->assertCount(1, $this->queries, 'one query per distinct cohort');
		$this->assertSame('learniq', $this->queries[0]['sourceApp']);
		$this->assertSame('c-1', $this->queries[0]['cohortId']);
		$this->assertSame('2026-09-28T00:00:00+02:00', $this->queries[0]['from']);
		$this->assertArrayNotHasKey('to', $this->queries[0]);

		$this->assertCount(2, $sessions);
		$this->assertSame('planninq', $sessions[0]['source']);
		$this->assertSame('Wiskunde', $sessions[0]['title']);
		$this->assertSame('A1.12', $sessions[0]['location'], 'room code when there is no label');
		$this->assertSame('scheduled', $sessions[0]['lifecycle']);
		$this->assertSame('jan', $sessions[0]['teacherUserId']);
		$this->assertSame('ne', $sessions[1]['title'], 'subject when there is no title');
		$this->assertSame('Lokaal B2.04', $sessions[1]['location']);
		$this->assertSame('cancelled', $sessions[1]['lifecycle']);
	}//end testCohortLessonsComeFromPlanninq()

	/**
	 * A teacher's lessons are asked for by account.
	 *
	 * @return void
	 */
	public function testTeacherLessonsAreAskedByAccount(): void {
		$source = new PlanninqTimetableSource($this->appManager(true), $this->dispatcher());

		$source->sessionsForTeacher('jan', null, '2026-10-04T23:59:59+02:00');

		$this->assertSame('jan', $this->queries[0]['teacherUserId']);
		$this->assertSame(1000, $this->queries[0]['limit']);
		$this->assertSame([], $source->sessionsForTeacher('', null, null));
	}//end testTeacherLessonsAreAskedByAccount()

	/**
	 * A silent or refusing planninq raises instead of reading as an empty timetable.
	 *
	 * @return void
	 */
	public function testSilentOrRefusingPlanninqRaises(): void {
		foreach (['silent' => 'did not answer', 'refuse' => 'refused'] as $mode => $message) {
			$source = new PlanninqTimetableSource($this->appManager(true), $this->dispatcher($mode));
			try {
				$source->sessionsForCohorts(['c-1'], null, null);
				$this->fail("a {$mode} planninq must not read as empty");
			} catch (RuntimeException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}
	}//end testSilentOrRefusingPlanninqRaises()

	/**
	 * The resolver picks planninq only when it is installed and not switched off.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function testResolverPicksTheSource(): void {
		$local = new LocalSessionTimetableSource($this->createMock(ObjectService::class));

		$cases = [
			'installed' => [true, 'auto', 'planninq'],
			'absent' => [false, 'auto', 'learniq'],
			'forced local' => [true, 'learniq', 'learniq'],
		];
		foreach ($cases as $label => [$installed, $setting, $expected]) {
			$config = $this->createMock(IAppConfig::class);
			$config->method('getValueString')->willReturn($setting);
			$resolver = new TimetableSourceResolver(
				$config,
				$local,
				new PlanninqTimetableSource($this->appManager($installed), $this->dispatcher())
			);

			$this->assertSame($expected, $resolver->current()->name(), $label);
			$this->assertSame($expected === 'planninq', $resolver->usesPlanninq(), $label);
		}

		$absentClass = new PlanninqTimetableSource($this->appManager(true), $this->dispatcher(), 'OCA\\Planninq\\Event\\NoSuchEvent');
		$this->assertFalse($absentClass->isAvailable(), 'installed but without the event class is not available');
	}//end testResolverPicksTheSource()

	/**
	 * The local source reads Sessions per cohort, marks them learniq, and knows no teacher lessons.
	 *
	 * @return void
	 */
	public function testLocalSourceReadsSessionsPerCohort(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('findAll')->willReturnCallback(
			static fn (array $config): array => [
				['id' => 's-1', 'cohortId' => $config['filters']['cohortId'], 'title' => 'Biologie'],
				['id' => 's-x', 'cohortId' => 'other', 'title' => 'Leak'],
			]
		);
		$local = new LocalSessionTimetableSource($objectService);

		$sessions = $local->sessionsForCohorts(['c-1'], null, null);

		$this->assertSame(['s-1'], array_column($sessions, 'id'), 'a mismatched cohort never gets through');
		$this->assertSame('learniq', $sessions[0]['source']);
	}//end testLocalSourceReadsSessionsPerCohort()

	/**
	 * The local source's teacher lessons are the ones the caller covers as
	 * substitute, read on substituteTeacherId and marked as cover (learniq#1134).
	 *
	 * @return void
	 */
	public function testLocalSourceReadsTheLessonsTheCallerCovers(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->assertSame('jan', $config['filters']['substituteTeacherId'] ?? null);
				$this->assertArrayNotHasKey('cohortId', $config['filters']);
				return [
					['id' => 's-cover', 'cohortId' => 'c-9', 'substituteTeacherId' => 'jan'],
					['id' => 's-other', 'cohortId' => 'c-9', 'substituteTeacherId' => 'piet'],
				];
			}
		);
		$local = new LocalSessionTimetableSource($objectService);

		$sessions = $local->sessionsForTeacher('jan', null, null);

		$this->assertSame(['s-cover'], array_column($sessions, 'id'), 'a lesson another teacher covers never gets through');
		$this->assertTrue($sessions[0]['cover']);
		$this->assertSame('learniq', $sessions[0]['source']);
	}//end testLocalSourceReadsTheLessonsTheCallerCovers()
}//end class
