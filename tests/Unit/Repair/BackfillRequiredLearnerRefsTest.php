<?php

/**
 * Tests for BackfillRequiredLearnerRefs.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillRequiredLearnerRefs;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for BackfillRequiredLearnerRefs::run(), over the real resolver.
 */
class BackfillRequiredLearnerRefsTest extends TestCase {

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Lines the step reported.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Build the step over the real resolver and the fake store.
	 *
	 * @param string|null $failSaveFor A row uuid whose save throws.
	 *
	 * @return BackfillRequiredLearnerRefs
	 */
	private function makeStep(?string $failSaveFor = null): BackfillRequiredLearnerRefs {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'learner-profile' => [
				['id' => 'profile-vera', 'ncUserId' => 'po-leerling-147'],
			],
			'excuse-request' => [
				['id' => 'excuse-old', 'learnerId' => 'po-leerling-147', 'learnerRef' => null, '@self' => ['owner' => 'someone']],
				['id' => 'excuse-done', 'learnerId' => 'po-leerling-147', 'learnerRef' => 'profile-vera'],
				['id' => 'excuse-orphan', 'learnerId' => 'gone-user'],
				['id' => 'excuse-no-learner'],
			],
			'conference-signup' => [
				['id' => 'signup-old', 'learnerId' => 'po-leerling-147'],
				['id' => 'signup-broken', 'learnerId' => 'po-leerling-147'],
			],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($failSaveFor) {
				if ($uuid === $failSaveFor) {
					throw new RuntimeException('locked');
				}

				return $this->store->save((string)$schema, $object, $uuid);
			}
		);

		return new BackfillRequiredLearnerRefs(
			objectService: $objectService,
			learnerRefs: new LearnerRefResolver(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStep()

	/**
	 * An output double that records what the step reports.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$this->messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->messages[] = $message;
		});
		return $output;
	}//end output()

	/**
	 * A row without the pupil's profile gets it; a row with one, a row whose
	 * pupil has no profile and a row with no pupil are not saved.
	 *
	 * @return void
	 */
	public function testRowsWithoutTheProfileGetIt(): void {
		$this->makeStep()->run($this->recordingOutput());

		$saved = [];
		foreach ($this->store->saves as $save) {
			$saved[$save['schema'] . ':' . $save['uuid']] = $save['object'];
		}

		self::assertSame(['excuse-request:excuse-old', 'conference-signup:signup-old', 'conference-signup:signup-broken'], array_keys($saved));
		self::assertSame('profile-vera', $saved['excuse-request:excuse-old']['learnerRef']);
		self::assertArrayNotHasKey('@self', $saved['excuse-request:excuse-old']);
		self::assertSame('profile-vera', $saved['conference-signup:signup-old']['learnerRef']);
		self::assertContains('BackfillRequiredLearnerRefs excuse-request: 1 stamped, 1 without a learner profile, 0 failed, of 4 scanned.', $this->messages);
	}//end testRowsWithoutTheProfileGetIt()

	/**
	 * A second run saves nothing.
	 *
	 * @return void
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep();
		$step->run($this->recordingOutput());
		$first = count($this->store->saves);
		$step->run($this->recordingOutput());

		self::assertSame($first, count($this->store->saves));
	}//end testASecondRunSavesNothing()

	/**
	 * A failing save is counted and the other rows still land.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCounted(): void {
		$this->makeStep(failSaveFor: 'signup-broken')->run($this->recordingOutput());

		self::assertContains('BackfillRequiredLearnerRefs conference-signup: 1 stamped, 0 without a learner profile, 1 failed, of 2 scanned.', $this->messages);
	}//end testAFailedSaveIsCounted()

	/**
	 * When OpenRegister cannot be read, nothing is written and the step ends;
	 * array rows are read like entities.
	 *
	 * @return void
	 */
	public function testAFailedReadWritesNothingAndArrayRowsAreRead(): void {
		$step = $this->makeStep();
		$this->store->failReads = 'database gone';
		$step->run($this->recordingOutput());
		self::assertSame([], $this->store->saves);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => (($config['filters']['schema'] === 'excuse-request' && ($config['offset'] ?? 0) === 0) ? [['id' => 'excuse-1', 'learnerRef' => 'profile-vera'], 'not-a-row'] : [])
		);
		$objectService->expects(self::never())->method('saveObject');
		(new BackfillRequiredLearnerRefs(objectService: $objectService, learnerRefs: new LearnerRefResolver(objectService: $objectService), logger: new NullLogger()))->run($this->recordingOutput());
		self::assertContains('BackfillRequiredLearnerRefs excuse-request: 0 stamped, 0 without a learner profile, 0 failed, of 1 scanned.', $this->messages);
	}//end testAFailedReadWritesNothingAndArrayRowsAreRead()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesItsWork(): void {
		self::assertStringContainsString('learner profile', $this->makeStep()->getName());
	}//end testTheStepNamesItsWork()
}//end class
