<?php

/**
 * Learniq ReportCardGradeLinesStamp unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\ReportCardGradeLinesStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ReportCardGradeLines;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ReportCardGradeLinesStamp::handle().
 */
class ReportCardGradeLinesStampTest extends TestCase {

	private const PERIOD = 'period-1';

	/**
	 * The fake OpenRegister store behind the real service.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the listener over a real ReportCardGradeLines and the fake store.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return ReportCardGradeLinesStamp
	 */
	private function makeStamp(string $slug = 'report-card'): ReportCardGradeLinesStamp {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'report-period' => [['id' => self::PERIOD, 'name' => 'Rapport 1']],
			'course' => [['id' => 'course-rekenen', 'name' => 'Rekenen']],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new ReportCardGradeLinesStamp(
			schemaResolver: $schemaResolver,
			gradeLines: new ReportCardGradeLines(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * A report card as the composer writes it.
	 *
	 * @param float $grade The Rekenen period average.
	 *
	 * @return array<string, mixed>
	 */
	private function card(float $grade): array {
		return [
			'learnerId' => 'pupil-1',
			'reportPeriodId' => self::PERIOD,
			'lifecycle' => 'draft',
			'subjectGrades' => [['curriculumPlanId' => 'plan-rekenen', 'courseId' => 'course-rekenen', 'periodAverage' => $grade]],
		];
	}//end card()

	/**
	 * A composed report card gets its period name and grade lines.
	 *
	 * @return void
	 */
	public function testACreateGetsThePeriodAndTheGradeLines(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->card(7.9), 'report-card'));
		$this->makeStamp()->handle($event);

		self::assertSame('Rapport 1', $event->getModifiedData()['periodName']);
		self::assertSame(['Rekenen: 7,9'], $event->getModifiedData()['gradeLines']);
		self::assertFalse($event->isPropagationStopped());
	}//end testACreateGetsThePeriodAndTheGradeLines()

	/**
	 * A recompose that changes a grade changes its line, and a value a
	 * caller sends for the lines is replaced by the derived one.
	 *
	 * @return void
	 */
	public function testAnUpdateFollowsTheGradesAndReplacesSentLines(): void {
		$new = array_merge($this->card(6.4), ['gradeLines' => ['Rekenen: 10,0'], 'periodName' => 'Made up']);
		$old = array_merge($this->card(7.9), ['gradeLines' => ['Rekenen: 7,9'], 'periodName' => 'Rapport 1']);
		$event = new ObjectUpdatingEvent(OrEntityFactory::make($new, 'report-card'), OrEntityFactory::make($old, 'report-card'));
		$this->makeStamp()->handle($event);

		self::assertSame(['Rekenen: 6,4'], $event->getModifiedData()['gradeLines']);
		self::assertSame('Rapport 1', $event->getModifiedData()['periodName']);
	}//end testAnUpdateFollowsTheGradesAndReplacesSentLines()

	/**
	 * When OpenRegister cannot be read, an update keeps the lines it had and
	 * the write goes through.
	 *
	 * @return void
	 */
	public function testAFailedReadKeepsTheStoredLinesOnUpdate(): void {
		$old = array_merge($this->card(7.9), ['gradeLines' => ['Rekenen: 7,9'], 'periodName' => 'Rapport 1']);
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($old, ['mentorComment' => 'Goed gedaan.']), 'report-card'),
			OrEntityFactory::make($old, 'report-card')
		);
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$stamp->handle($event);

		self::assertSame(['Rekenen: 7,9'], $event->getModifiedData()['gradeLines']);
		self::assertSame('Rapport 1', $event->getModifiedData()['periodName']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedReadKeepsTheStoredLinesOnUpdate()

	/**
	 * When OpenRegister cannot be read on a create, the card is stored
	 * without lines rather than refused.
	 *
	 * @return void
	 */
	public function testAFailedReadStoresNoLinesOnCreate(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->card(7.9), 'report-card'));
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$stamp->handle($event);

		self::assertSame([], $event->getModifiedData()['gradeLines']);
		self::assertNull($event->getModifiedData()['periodName']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedReadStoresNoLinesOnCreate()

	/**
	 * Another schema's write is never touched.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->card(7.9), 'final-grade'));
		$this->makeStamp(slug: 'final-grade')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The stamp is wired for both creates and updates, so the composer, a
	 * recompose and an edit all pass it.
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

		self::assertContains(ObjectCreatingEvent::class . ' => ' . ReportCardGradeLinesStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . ReportCardGradeLinesStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
