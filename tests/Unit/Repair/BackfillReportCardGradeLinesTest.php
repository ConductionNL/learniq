<?php

/**
 * Learniq BackfillReportCardGradeLines unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-existing-report-cards-get-their-readable-grades
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillReportCardGradeLines;
use OCA\Learniq\Service\ReportCardGradeLines;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for BackfillReportCardGradeLines::run().
 */
class BackfillReportCardGradeLinesTest extends TestCase {

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Messages the step reported.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Warnings the step reported.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * When set, every save is refused with the message it returns for the
	 * object, the way OpenRegister's validation throws.
	 *
	 * @var (callable(array<string, mixed>): string)|null
	 */
	private $refuseSaves = null;

	/**
	 * Build the step over the fake store: one period, one course, and two
	 * report cards, one composed before the stamp and one already stamped.
	 *
	 * @return BackfillReportCardGradeLines
	 */
	private function makeStep(): BackfillReportCardGradeLines {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'report-period' => [['id' => 'period-1', 'name' => 'Rapport 1']],
			'course' => [['id' => 'course-rekenen', 'name' => 'Rekenen']],
			'report-card' => [
				[
					'@self' => ['id' => 'card-old', 'folder' => 'f1'],
					'id' => 'card-old',
					'reportPeriodId' => 'period-1',
					'lifecycle' => 'published-to-parents',
					'subjectGrades' => [['curriculumPlanId' => 'plan-rekenen', 'courseId' => 'course-rekenen', 'periodAverage' => 7.9]],
				],
				[
					'id' => 'card-done',
					'reportPeriodId' => 'period-1',
					'lifecycle' => 'published-to-parents',
					'subjectGrades' => [['curriculumPlanId' => 'plan-rekenen', 'courseId' => 'course-rekenen', 'periodAverage' => 6.0]],
					'periodName' => 'Rapport 1',
					'gradeLines' => ['Rekenen: 6,0'],
				],
			],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) {
				if ($this->refuseSaves !== null) {
					throw new \RuntimeException(($this->refuseSaves)($object));
				}

				return $this->store->save((string)$schema, $object, $uuid);
			}
		);

		return new BackfillReportCardGradeLines(
			objectService: $objectService,
			gradeLines: new ReportCardGradeLines(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStep()

	/**
	 * An output double that records info lines.
	 *
	 * @return IOutput
	 */
	private function recorder(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->messages[] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		return $output;
	}//end recorder()

	/**
	 * A card composed before the stamp gets its period and lines; a card
	 * already stamped is not saved again.
	 *
	 * @return void
	 */
	public function testAnOldCardIsStampedAndAStampedOneIsLeftAlone(): void {
		$this->makeStep()->run($this->recorder());

		self::assertCount(1, $this->store->saves);
		$save = $this->store->saves[0];
		self::assertSame('card-old', $save['uuid']);
		self::assertSame('report-card', $save['schema']);
		self::assertSame('Rapport 1', $save['object']['periodName']);
		self::assertSame(['Rekenen: 7,9'], $save['object']['gradeLines']);
		self::assertArrayNotHasKey('@self', $save['object'], 'the session-less save must not carry the folder block');
		self::assertSame('published-to-parents', $save['object']['lifecycle'], 'the card keeps its state');
		self::assertSame(['BackfillReportCardGradeLines: 1 stamped, 0 failed, of 2 scanned.'], $this->messages);
	}//end testAnOldCardIsStampedAndAStampedOneIsLeftAlone()

	/**
	 * A second run saves nothing.
	 *
	 * @return void
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep();
		$step->run($this->recorder());
		$step->run($this->recorder());

		self::assertCount(1, $this->store->saves);
		self::assertSame('BackfillReportCardGradeLines: 0 stamped, 0 failed, of 2 scanned.', $this->messages[1]);
	}//end testASecondRunSavesNothing()

	/**
	 * Without OpenRegister the step stops quietly and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterWritesNothing(): void {
		$step = $this->makeStep();
		$this->store->failReads = 'register not imported';
		$step->run($this->recorder());

		self::assertSame([], $this->store->saves);
		self::assertSame(['BackfillReportCardGradeLines: 0 stamped, 0 failed, of 0 scanned.'], $this->messages);
	}//end testAnUnreadableRegisterWritesNothing()

	/**
	 * A refused write names its reason in the step's own output, grouped,
	 * not only in the log: "0 stamped, 294 failed" said nothing about why.
	 *
	 * Live pass lane 10 (5 Oct): every refusal on the instance was OpenRegister
	 * refusing a null subjectGrades[].teacherComment (declared nullable, but
	 * openregister before #4296 did not widen nested nullable properties).
	 * The message below is OpenRegister's, copied from that log.
	 *
	 * @return void
	 */
	public function testARefusedWriteNamesItsReasonInTheOutput(): void {
		$step = $this->makeStep();
		$this->store->rows['report-card'][] = [
			'id' => 'card-old-2',
			'reportPeriodId' => 'period-1',
			'lifecycle' => 'published-to-parents',
			'subjectGrades' => [
				['curriculumPlanId' => 'plan-rekenen', 'courseId' => 'course-rekenen', 'periodAverage' => 7.0, 'teacherComment' => null],
				['curriculumPlanId' => 'plan-taal', 'courseId' => null, 'periodAverage' => 6.5, 'teacherComment' => null],
			],
		];
		$this->refuseSaves = static fn (array $object): string => "Property 'subjectGrades." . (count($object['subjectGrades']) - 1)
			. ".teacherComment' should be type 'string' but is 'null'. Please provide a value of the correct type.";

		$step->run($this->recorder());

		self::assertSame('BackfillReportCardGradeLines: 0 stamped, 2 failed, of 3 scanned.', $this->messages[0]);
		self::assertCount(1, $this->warnings);
		self::assertStringContainsString(
			"2 x Property 'subjectGrades.N.teacherComment' should be type 'string' but is 'null'",
			$this->warnings[0]
		);
	}//end testARefusedWriteNamesItsReasonInTheOutput()
}//end class
