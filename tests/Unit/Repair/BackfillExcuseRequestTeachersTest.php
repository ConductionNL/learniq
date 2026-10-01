<?php

/**
 * Tests for the repair step that stamps teacherIds on absence reports
 * written before the server did.
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
 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-existing-absence-reports-get-their-group-teachers
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillExcuseRequestTeachers;
use OCA\Learniq\Service\PupilGroupTeachers;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for BackfillExcuseRequestTeachers::run().
 */
class BackfillExcuseRequestTeachersTest extends TestCase {

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
	 * Build the step over the fake store, with two groups.
	 *
	 * @return BackfillExcuseRequestTeachers
	 */
	private function makeStep(): BackfillExcuseRequestTeachers {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['cohort'] = [
			['id' => 'groep-7', 'lifecycle' => 'active', 'learnerIds' => ['pupil-1'], 'teacherIds' => ['juf-7']],
			['id' => 'groep-8', 'lifecycle' => 'active', 'learnerIds' => ['pupil-2'], 'teacherIds' => ['juf-8', 'duo-8']],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new BackfillExcuseRequestTeachers(
			objectService: $objectService,
			groupTeachers: new PupilGroupTeachers(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStep()

	/**
	 * An output double that records info lines.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->messages[] = $message;
			}
		);

		return $output;
	}//end repairOutput()

	/**
	 * The saved object for a report id.
	 *
	 * @param string $id Report id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function savedFor(string $id): ?array {
		foreach ($this->store->saves as $save) {
			if ($save['uuid'] === $id) {
				return $save['object'];
			}
		}

		return null;
	}//end savedFor()

	/**
	 * Every report gets the teachers of its pupil's group; one that already
	 * carries them, in any order, or has no pupil, is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#scenario-an-old-report-reaches-the-group-teacher
	 */
	public function testStampsWhatTheServerWouldStampToday(): void {
		$step = $this->makeStep();
		$this->store->rows['excuse-request'] = [
			['id' => 'er-old', 'learnerId' => 'pupil-1', 'reason' => 'Koorts', '@self' => ['folder' => '208', 'owner' => '__system__']],
			['id' => 'er-done', 'learnerId' => 'pupil-2', 'teacherIds' => ['duo-8', 'juf-8']],
			['id' => 'er-stale', 'learnerId' => 'pupil-2', 'teacherIds' => ['juf-7']],
			['id' => 'er-nogroup', 'learnerId' => 'pupil-9', 'teacherIds' => []],
			['id' => 'er-nopupil'],
		];

		$step->run($this->repairOutput());

		self::assertEqualsCanonicalizing(['er-old', 'er-stale'], array_column($this->store->saves, 'uuid'));
		self::assertSame(['juf-7'], $this->savedFor('er-old')['teacherIds']);
		self::assertSame('Koorts', $this->savedFor('er-old')['reason'], 'the rest of the report is kept');
		self::assertArrayNotHasKey('@self', $this->savedFor('er-old'), 'the metadata block is not written back');
		self::assertSame(['juf-8', 'duo-8'], $this->savedFor('er-stale')['teacherIds']);
		self::assertStringContainsString('2 stamped, 0 failed, of 5 scanned', $this->messages[0]);
	}//end testStampsWhatTheServerWouldStampToday()

	/**
	 * A second run saves nothing new.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#scenario-a-second-run-changes-nothing
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep();
		$this->store->rows['excuse-request'] = [['id' => 'er-1', 'learnerId' => 'pupil-2']];

		$step->run($this->repairOutput());
		$step->run($this->repairOutput());

		self::assertCount(1, $this->store->saves);
	}//end testASecondRunSavesNothing()

	/**
	 * The step runs without a session, and each pupil's groups are read once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-existing-absence-reports-get-their-group-teachers
	 */
	public function testReadsAreUnscopedAndGroupsAreCached(): void {
		$step = $this->makeStep();
		$this->store->rows['excuse-request'] = [
			['id' => 'er-1', 'learnerId' => 'pupil-1'],
			['id' => 'er-2', 'learnerId' => 'pupil-1'],
			['id' => 'er-3', 'learnerId' => 'pupil-2'],
		];

		$step->run($this->repairOutput());

		$cohortReads = array_values(array_filter($this->store->reads, static fn (array $r): bool => ($r['config']['filters']['schema'] ?? '') === 'cohort'));
		self::assertCount(2, $cohortReads);
		foreach ($this->store->reads as $read) {
			self::assertFalse($read['rbac']);
			self::assertFalse($read['multitenancy']);
		}
	}//end testReadsAreUnscopedAndGroupsAreCached()

	/**
	 * A failing lookup never wipes stored teachers: the row is skipped and counted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#scenario-a-failed-lookup-never-widens-the-audience
	 */
	public function testAFailedLookupLeavesTheRowAsItWas(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = []): array {
				if (($config['filters']['schema'] ?? '') === 'excuse-request') {
					return [['id' => 'er-1', 'learnerId' => 'pupil-1', 'teacherIds' => ['juf-7']]];
				}

				throw new RuntimeException('database gone');
			}
		);
		$objectService->expects($this->never())->method('saveObject');

		$step = new BackfillExcuseRequestTeachers(
			objectService: $objectService,
			groupTeachers: new PupilGroupTeachers(objectService: $objectService),
			logger: new NullLogger(),
		);
		$step->run($this->repairOutput());

		self::assertStringContainsString('1 failed', $this->messages[0]);
	}//end testAFailedLookupLeavesTheRowAsItWas()

	/**
	 * The step is registered after the register import, so an upgrade runs it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-existing-absence-reports-get-their-group-teachers
	 */
	public function testTheStepRunsOnUpgrade(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		self::assertContains(BackfillExcuseRequestTeachers::class, $steps);
		$initialize = array_search('OCA\\Learniq\\Repair\\InitializeSettings', $steps, true);
		self::assertIsInt($initialize, 'InitializeSettings is a post-migration step');
		self::assertGreaterThan(
			$initialize,
			array_search(BackfillExcuseRequestTeachers::class, $steps, true),
			'the register declares the field before the step writes it'
		);
	}//end testTheStepRunsOnUpgrade()

	/**
	 * More than one page is walked to the end.
	 *
	 * @return void
	 */
	public function testWalksEveryPage(): void {
		$step = $this->makeStep();
		for ($i = 0; $i < 450; $i++) {
			$this->store->rows['excuse-request'][] = ['id' => 'er-' . $i, 'learnerId' => 'pupil-1'];
		}

		$step->run($this->repairOutput());

		self::assertCount(450, $this->store->saves);
	}//end testWalksEveryPage()
}//end class
