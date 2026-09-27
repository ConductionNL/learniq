<?php

/**
 * Learniq BackfillGradeEntryLearnerRef unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillGradeEntryLearnerRef;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for BackfillGradeEntryLearnerRef::run().
 */
class BackfillGradeEntryLearnerRefTest extends TestCase {

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
	 * Build the step over the fake store.
	 *
	 * @return BackfillGradeEntryLearnerRef
	 */
	private function makeStep(): BackfillGradeEntryLearnerRef {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learner-profile'] = [['id' => 'lp-1', 'ncUserId' => 'pupil-1']];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new BackfillGradeEntryLearnerRef(
			objectService: $objectService,
			learnerRefs: new LearnerRefResolver(objectService: $objectService),
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
	 * Only the unstamped row with a profile is saved, with the right value.
	 *
	 * @return void
	 */
	public function testStampsOnlyTheRowsThatNeedIt(): void {
		$step = $this->makeStep();
		$this->store->rows['grade-entry'] = [
			['id' => 'ge-1', 'learnerId' => 'pupil-1', 'value' => 6.0],
			['id' => 'ge-2', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-1'],
			['id' => 'ge-3', 'learnerId' => 'pupil-9'],
			['id' => 'ge-4'],
		];

		$step->run($this->repairOutput());

		self::assertCount(1, $this->store->saves);
		self::assertSame('ge-1', $this->store->saves[0]['uuid']);
		self::assertSame('lp-1', $this->store->saves[0]['object']['learnerRef']);
		self::assertSame(6.0, $this->store->saves[0]['object']['value']);
		self::assertStringContainsString('1 stamped, 1 without a learner profile', $this->messages[0]);
	}//end testStampsOnlyTheRowsThatNeedIt()

	/**
	 * A second run saves nothing new.
	 *
	 * @return void
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep();
		$this->store->rows['grade-entry'] = [['id' => 'ge-1', 'learnerId' => 'pupil-1']];

		$step->run($this->repairOutput());
		$step->run($this->repairOutput());

		self::assertCount(1, $this->store->saves);
	}//end testASecondRunSavesNothing()

	/**
	 * Reads run without session scoping, and a lookup per user happens once.
	 *
	 * @return void
	 */
	public function testReadsAreUnscopedAndProfilesAreCached(): void {
		$step = $this->makeStep();
		$this->store->rows['grade-entry'] = [
			['id' => 'ge-1', 'learnerId' => 'pupil-1'],
			['id' => 'ge-2', 'learnerId' => 'pupil-1'],
			['id' => 'ge-3', 'learnerId' => 'pupil-1'],
		];

		$step->run($this->repairOutput());

		$profileReads = array_filter(
			$this->store->reads,
			static fn (array $read): bool => ($read['config']['filters']['schema'] ?? '') === 'learner-profile'
		);
		$gradeReads = array_values(
			array_filter(
				$this->store->reads,
				static fn (array $read): bool => ($read['config']['filters']['schema'] ?? '') === 'grade-entry'
			)
		);
		self::assertCount(1, $profileReads);
		self::assertFalse($gradeReads[0]['rbac']);
		self::assertFalse($gradeReads[0]['multitenancy']);
		self::assertCount(3, $this->store->saves);
	}//end testReadsAreUnscopedAndProfilesAreCached()

	/**
	 * More than one page is walked to the end.
	 *
	 * @return void
	 */
	public function testWalksEveryPage(): void {
		$step = $this->makeStep();
		for ($i = 0; $i < 450; $i++) {
			$this->store->rows['grade-entry'][] = ['id' => 'ge-' . $i, 'learnerId' => 'pupil-1'];
		}

		$step->run($this->repairOutput());

		self::assertCount(450, $this->store->saves);
	}//end testWalksEveryPage()

	/**
	 * An unavailable store ends the run with a report, not an exception.
	 *
	 * @return void
	 */
	public function testAnUnavailableStoreDoesNotBreakTheUpgrade(): void {
		$step = $this->makeStep();
		$this->store->failReads = 'no register yet';

		$step->run($this->repairOutput());

		self::assertSame([], $this->store->saves);
		self::assertStringContainsString('0 stamped', $this->messages[0]);
	}//end testAnUnavailableStoreDoesNotBreakTheUpgrade()
}//end class
