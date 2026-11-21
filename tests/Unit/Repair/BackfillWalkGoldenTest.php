<?php

/**
 * Learniq back-fill walk golden tests.
 *
 * Pins how every paging back-fill step walks its schema: the reads it makes
 * (register, schema, limit, offset, unscoped), which rows it hands on (arrays
 * and serialisable objects, nothing else), how it stops when a page fails,
 * the warning it logs then, and the counts it reports.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use JsonSerializable;
use OCA\Learniq\Repair\BackfillExcuseRequestTeachers;
use OCA\Learniq\Repair\BackfillGradeEntryLearnerRef;
use OCA\Learniq\Repair\BackfillLearnerProfileNames;
use OCA\Learniq\Repair\BackfillLvsResultLearnerRef;
use OCA\Learniq\Repair\BackfillReadableCopies;
use OCA\Learniq\Repair\BackfillReportCardGradeLines;
use OCA\Learniq\Repair\BackfillRequiredLearnerRefs;
use OCA\Learniq\Repair\BackfillSubmissionLearnerRefs;
use OCA\Learniq\Repair\BackfillWerkprocesAssessmentLearner;
use OCA\Learniq\Service\ReportCardGradeLines;
use OCA\Learniq\Tests\Support\ContractImplementations;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Golden tests for the paging walk of the back-fill steps.
 */
class BackfillWalkGoldenTest extends TestCase {

	/**
	 * Every read, as [schema, limit, offset, rbac, multitenancy, register].
	 *
	 * @var list<array{0: mixed, 1: mixed, 2: mixed, 3: bool, 4: bool, 5: mixed}>
	 */
	private array $reads = [];

	/**
	 * Every message, as [channel, text, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $messages = [];

	/**
	 * Build a step whose store answers one full page and then fails.
	 *
	 * @param class-string<IRepairStep> $class The step.
	 *
	 * @return IRepairStep
	 */
	private function step(string $class): IRepairStep {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = [
					($config['filters']['schema'] ?? null),
					($config['limit'] ?? null),
					($config['offset'] ?? null),
					$_rbac,
					$_multitenancy,
					($config['filters']['register'] ?? null),
				];
				if (($config['offset'] ?? 0) > 0) {
					throw new RuntimeException('page two gone');
				}

				return $this->firstPage();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->messages[] = ['log', (string)$message, $context];
			}
		);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType) ? ContractImplementations::concrete($type->getName()) : '';
			$args[$parameter->getName()] = match ($name) {
				ObjectService::class => $objectService,
				LoggerInterface::class => $logger,
				default => $this->collaborator(name: $name),
			};
		}

		return new $class(...$args);
	}//end step()

	/**
	 * A collaborator double; the report-card deriver answers a period, so
	 * every row needs a save.
	 *
	 * @param class-string $name The collaborator class.
	 *
	 * @return object
	 */
	private function collaborator(string $name): object {
		$double = $this->createMock($name);
		if ($name === ReportCardGradeLines::class) {
			$double->method('derive')->willReturn(['periodName' => 'P1', 'gradeLines' => []]);
		}

		return $double;
	}//end collaborator()

	/**
	 * A full first page: 199 arrays and one serialisable object (200 rows), plus a string that is no row.
	 *
	 * @return array<int, mixed>
	 */
	private function firstPage(): array {
		$rows = [];
		for ($i = 0; $i < 199; $i++) {
			$rows[] = ['id' => 'row-' . $i, 'learnerId' => 'pupil-' . $i];
		}

		$rows[] = new class implements JsonSerializable {
			/**
			 * The row.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'row-json', 'learnerId' => 'pupil-json'];
			}//end jsonSerialize()
		};
		$rows[] = 'not a row';
		return $rows;
	}//end firstPage()

	/**
	 * An output double that records what the step reports.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $channel) {
			$output->method($channel)->willReturnCallback(
				function (string $message) use ($channel): void {
					$this->messages[] = [$channel, $message, []];
				}
			);
		}

		return $output;
	}//end repairOutput()

	/**
	 * The steps, the schemas they read and the messages they leave.
	 *
	 * @return array<string, array{0: class-string<IRepairStep>, 1: list<string>, 2: list<string>}>
	 */
	public static function steps(): array {
		return [
			'excuse request teachers' => [
				BackfillExcuseRequestTeachers::class,
				['excuse-request'],
				[
					'log [BackfillExcuseRequestTeachers] Stopped early: {msg} {"msg":"page two gone"}',
					'info BackfillExcuseRequestTeachers: 0 stamped, 0 failed, of 200 scanned. []',
				],
			],
			'grade entry learner ref' => [
				BackfillGradeEntryLearnerRef::class,
				['grade-entry'],
				[
					'log [BackfillGradeEntryLearnerRef] Stopped early: {msg} {"msg":"page two gone"}',
					'info BackfillGradeEntryLearnerRef: 0 stamped, 200 without a learner profile, 0 failed, of 200 scanned. []',
				],
			],
			'lvs result learner ref' => [
				BackfillLvsResultLearnerRef::class,
				['lvs-result'],
				[
					'log [BackfillLvsResultLearnerRef] Stopped early: {msg} {"msg":"page two gone"}',
					'info BackfillLvsResultLearnerRef: 0 stamped, 200 without a learner profile, 0 failed, of 200 scanned. []',
				],
			],
			'submission learner refs' => [
				BackfillSubmissionLearnerRefs::class,
				['submission'],
				[
					'log [BackfillSubmissionLearnerRefs] Stopped early: {msg} {"msg":"page two gone"}',
					'info BackfillSubmissionLearnerRefs: 0 stamped, 0 without a learner profile, 0 failed, of 200 scanned. []',
				],
			],
			'werkproces assessment learner' => [
				BackfillWerkprocesAssessmentLearner::class,
				['werkproces-assessment'],
				[
					'log [BackfillWerkprocesAssessmentLearner] Stopped early: {msg} {"msg":"page two gone"}',
					'info BackfillWerkprocesAssessmentLearner: 0 stamped, 0 whose placement names no learner, 0 failed, of 200 scanned. []',
				],
			],
			'required learner refs' => [
				BackfillRequiredLearnerRefs::class,
				['excuse-request', 'conference-signup'],
				[
					'log [BackfillRequiredLearnerRefs] Stopped early on {schema}: {msg} {"schema":"excuse-request","msg":"page two gone"}',
					'info BackfillRequiredLearnerRefs excuse-request: 0 stamped, 200 without a learner profile, 0 failed, of 200 scanned. []',
					'log [BackfillRequiredLearnerRefs] Stopped early on {schema}: {msg} {"schema":"conference-signup","msg":"page two gone"}',
					'info BackfillRequiredLearnerRefs conference-signup: 0 stamped, 200 without a learner profile, 0 failed, of 200 scanned. []',
				],
			],
			'learner profile names' => [
				BackfillLearnerProfileNames::class,
				['learner-profile'],
				[
					'log [BackfillLearnerProfileNames] Stopped early: {msg} {"msg":"page two gone"}',
					'info BackfillLearnerProfileNames: 0 named, 0 failed, of 200 scanned. []',
				],
			],
			'readable copies' => [
				BackfillReadableCopies::class,
				['grade-entry', 'enrolment', 'portfolio-share', 'teacher-availability', 'learner-profile'],
				[
					'log [BackfillReadableCopies] Stopped early on {schema}: {msg} {"schema":"grade-entry","msg":"page two gone"}',
					'info BackfillReadableCopies grade-entry: 0 stamped, 0 failed, of 200 scanned. []',
					'log [BackfillReadableCopies] Stopped early on {schema}: {msg} {"schema":"enrolment","msg":"page two gone"}',
					'info BackfillReadableCopies enrolment: 0 stamped, 0 failed, of 200 scanned. []',
					'log [BackfillReadableCopies] Stopped early on {schema}: {msg} {"schema":"portfolio-share","msg":"page two gone"}',
					'info BackfillReadableCopies portfolio-share: 0 stamped, 0 failed, of 200 scanned. []',
					'log [BackfillReadableCopies] Stopped early on {schema}: {msg} {"schema":"teacher-availability","msg":"page two gone"}',
					'info BackfillReadableCopies teacher-availability: 0 stamped, 0 failed, of 200 scanned. []',
					'log [BackfillReadableCopies] Stopped early on {schema}: {msg} {"schema":"learner-profile","msg":"page two gone"}',
					'info BackfillReadableCopies learner-profile: 0 stamped, 0 failed, of 200 scanned. []',
				],
			],
			'report card grade lines' => [
				BackfillReportCardGradeLines::class,
				['report-card'],
				[
					'log [BackfillReportCardGradeLines] Stopped early: {msg} {"msg":"page two gone"}',
					'info BackfillReportCardGradeLines: 200 stamped, 0 failed, of 200 scanned. []',
				],
			],
		];
	}//end steps()

	/**
	 * Each step reads every schema unscoped, page by page, stops on the
	 * failed second page, and reports what it scanned.
	 *
	 * @param class-string<IRepairStep> $class The step.
	 * @param list<string> $schemas The schemas it reads, in order.
	 * @param list<string> $expected Its messages, in order.
	 *
	 * @return void
	 *
	 * @dataProvider steps
	 */
	public function testTheWalkReadsPagesAndStopsOnAFailedPage(string $class, array $schemas, array $expected): void {
		$this->step(class: $class)->run($this->repairOutput());

		$reads = [];
		foreach ($schemas as $schema) {
			$reads[] = [$schema, 200, 0, false, false, 'learniq'];
			$reads[] = [$schema, 200, 200, false, false, 'learniq'];
		}

		self::assertSame($reads, $this->reads);
		$lines = array_map(
			static fn (array $message): string => $message[0] . ' ' . $message[1] . ' ' . json_encode($message[2]),
			array_values(array_filter($this->messages, static fn (array $m): bool => $m[0] !== 'log' || str_contains($m[1], 'Stopped early')))
		);
		self::assertSame($expected, $lines);
	}//end testTheWalkReadsPagesAndStopsOnAFailedPage()
}//end class
