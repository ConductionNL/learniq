<?php

/**
 * Tests for the repair step that stamps learnerRefs and learnerRef on
 * Submissions written before the server did.
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
 * @spec openspec/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillSubmissionLearnerRefs;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for BackfillSubmissionLearnerRefs::run().
 */
class BackfillSubmissionLearnerRefsTest extends TestCase {

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
	 * Build the step over the fake store, with two pupils who have a profile.
	 *
	 * @return BackfillSubmissionLearnerRefs
	 */
	private function makeStep(): BackfillSubmissionLearnerRefs {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'pupil-1'],
			['id' => 'lp-2', 'ncUserId' => 'pupil-2'],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new BackfillSubmissionLearnerRefs(
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
	 * The saved object for a submission id.
	 *
	 * @param string $id Submission id.
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
	 * Every submission gets the profiles of its learners; one that already
	 * carries them, or whose learners have no profile, is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-an-old-group-submission-reaches-the-portal
	 */
	public function testStampsWhatTheServerWouldStampToday(): void {
		$step = $this->makeStep();
		$this->store->rows['submission'] = [
			['id' => 'sub-group', 'learnerIds' => ['pupil-1', 'pupil-2'], 'assignmentId' => 'as-1'],
			['id' => 'sub-done', 'learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-1'], 'learnerRef' => 'lp-1'],
			['id' => 'sub-noprofile', 'learnerIds' => ['pupil-9']],
			['id' => 'sub-partial', 'learnerIds' => ['pupil-9', 'pupil-2']],
			['id' => 'sub-stale', 'learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-2'], 'learnerRef' => 'lp-2'],
			['id' => 'sub-nolearners'],
		];

		$step->run($this->repairOutput());

		self::assertEqualsCanonicalizing(['sub-group', 'sub-partial', 'sub-stale'], array_column($this->store->saves, 'uuid'));
		self::assertSame(['lp-1', 'lp-2'], $this->savedFor('sub-group')['learnerRefs']);
		self::assertSame('lp-1', $this->savedFor('sub-group')['learnerRef']);
		self::assertSame('as-1', $this->savedFor('sub-group')['assignmentId']);
		self::assertSame(['lp-2'], $this->savedFor('sub-partial')['learnerRefs']);
		self::assertNull($this->savedFor('sub-partial')['learnerRef'], 'learnerRef is the first learner, who has no profile');
		self::assertSame(['lp-1'], $this->savedFor('sub-stale')['learnerRefs']);
		self::assertSame('lp-1', $this->savedFor('sub-stale')['learnerRef']);
		self::assertStringContainsString('3 stamped, 1 without a learner profile', $this->messages[0]);
	}//end testStampsWhatTheServerWouldStampToday()

	/**
	 * A second run saves nothing new.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-second-run-changes-nothing
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep();
		$this->store->rows['submission'] = [['id' => 'sub-1', 'learnerIds' => ['pupil-1', 'pupil-2']]];

		$step->run($this->repairOutput());
		$step->run($this->repairOutput());

		self::assertCount(1, $this->store->saves);
	}//end testASecondRunSavesNothing()

	/**
	 * The step runs without a session: reads skip RBAC and tenant scoping,
	 * and each learner is looked up once.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
	 */
	public function testReadsAreUnscopedAndProfilesAreCached(): void {
		$step = $this->makeStep();
		$this->store->rows['submission'] = [
			['id' => 'sub-1', 'learnerIds' => ['pupil-1']],
			['id' => 'sub-2', 'learnerIds' => ['pupil-1', 'pupil-2']],
			['id' => 'sub-3', 'learnerIds' => ['pupil-2']],
		];

		$step->run($this->repairOutput());

		$profileReads = array_values(array_filter($this->store->reads, static fn (array $r): bool => ($r['config']['filters']['schema'] ?? '') === 'learner-profile'));
		$submissionReads = array_values(array_filter($this->store->reads, static fn (array $r): bool => ($r['config']['filters']['schema'] ?? '') === 'submission'));
		self::assertCount(2, $profileReads);
		foreach (array_merge($profileReads, $submissionReads) as $read) {
			self::assertFalse($read['rbac']);
			self::assertFalse($read['multitenancy']);
		}
	}//end testReadsAreUnscopedAndProfilesAreCached()

	/**
	 * A failing lookup never wipes stored values: the row is skipped and counted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-failed-lookup-leaves-the-row-as-it-was
	 */
	public function testAFailedLookupLeavesTheRowAsItWas(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = []): array {
				if (($config['filters']['schema'] ?? '') === 'submission') {
					return [['id' => 'sub-1', 'learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-1'], 'learnerRef' => 'lp-1']];
				}

				throw new RuntimeException('database gone');
			}
		);
		$objectService->expects($this->never())->method('saveObject');

		$step = new BackfillSubmissionLearnerRefs(
			objectService: $objectService,
			learnerRefs: new LearnerRefResolver(objectService: $objectService),
			logger: new NullLogger(),
		);
		$step->run($this->repairOutput());

		self::assertStringContainsString('1 failed', $this->messages[0]);
	}//end testAFailedLookupLeavesTheRowAsItWas()

	/**
	 * The step is registered, so an upgrade runs it: a repair step with a full
	 * test suite and no registration never runs anywhere.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
	 */
	public function testTheStepRunsOnUpgrade(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		self::assertContains(BackfillSubmissionLearnerRefs::class, $steps);
		$initialize = array_search('OCA\\Learniq\\Repair\\InitializeSettings', $steps, true);
		self::assertIsInt($initialize, 'InitializeSettings is a post-migration step');
		self::assertGreaterThan(
			$initialize,
			array_search(BackfillSubmissionLearnerRefs::class, $steps, true),
			'the register exists before the step reads it'
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
			$this->store->rows['submission'][] = ['id' => 'sub-' . $i, 'learnerIds' => ['pupil-1']];
		}

		$step->run($this->repairOutput());

		self::assertCount(450, $this->store->saves);
	}//end testWalksEveryPage()
}//end class
