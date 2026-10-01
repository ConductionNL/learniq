<?php

/**
 * Learniq BackfillLvsResultLearnerRef unit tests.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-lvsresults-are-back-filled-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillLvsResultLearnerRef;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for BackfillLvsResultLearnerRef::run().
 */
class BackfillLvsResultLearnerRefTest extends TestCase {

	private const TENANT_A = '00000000-0000-4000-8000-00000000000a';
	private const TENANT_B = '00000000-0000-4000-8000-00000000000b';

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
	 * @return BackfillLvsResultLearnerRef
	 */
	private function makeStep(): BackfillLvsResultLearnerRef {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'pupil-1', 'tenant_id' => self::TENANT_A],
			['id' => 'lp-3', 'ncUserId' => 'pupil-1', 'tenant_id' => self::TENANT_B],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new BackfillLvsResultLearnerRef(
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
	 * Only the unstamped row with a profile is saved, with the right value,
	 * and its score is left as it was.
	 *
	 * @return void
	 */
	public function testStampsOnlyTheRowsThatNeedIt(): void {
		$step = $this->makeStep();
		$this->store->rows['lvs-result'] = [
			['id' => 'lvs-1', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'vaardigheidsscore' => 56.7, 'lifecycle' => 'verified'],
			['id' => 'lvs-2', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-1'],
			['id' => 'lvs-3', 'learnerId' => 'pupil-9', 'tenant_id' => self::TENANT_A],
			['id' => 'lvs-4'],
		];

		$step->run($this->repairOutput());

		self::assertCount(1, $this->store->saves);
		self::assertSame('lvs-1', $this->store->saves[0]['uuid']);
		self::assertSame('lvs-result', $this->store->saves[0]['schema']);
		self::assertSame('lp-1', $this->store->saves[0]['object']['learnerRef']);
		self::assertSame(56.7, $this->store->saves[0]['object']['vaardigheidsscore']);
		self::assertSame('verified', $this->store->saves[0]['object']['lifecycle']);
		self::assertStringContainsString('1 stamped, 1 without a learner profile', $this->messages[0]);
	}//end testStampsOnlyTheRowsThatNeedIt()

	/**
	 * The profile is the one in the result's own tenant.
	 *
	 * @return void
	 */
	public function testTheProfileComesFromTheResultsTenant(): void {
		$step = $this->makeStep();
		$this->store->rows['lvs-result'] = [
			['id' => 'lvs-a', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A],
			['id' => 'lvs-b', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_B],
		];

		$step->run($this->repairOutput());

		self::assertSame('lp-1', $this->store->saves[0]['object']['learnerRef']);
		self::assertSame('lp-3', $this->store->saves[1]['object']['learnerRef']);
	}//end testTheProfileComesFromTheResultsTenant()

	/**
	 * A second run saves nothing new.
	 *
	 * @return void
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep();
		$this->store->rows['lvs-result'] = [['id' => 'lvs-1', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A]];

		$step->run($this->repairOutput());
		$step->run($this->repairOutput());

		self::assertCount(1, $this->store->saves);
	}//end testASecondRunSavesNothing()

	/**
	 * Reads run without session scoping, and a lookup per learner happens once.
	 *
	 * @return void
	 */
	public function testReadsAreUnscopedAndProfilesAreCached(): void {
		$step = $this->makeStep();
		$this->store->rows['lvs-result'] = [
			['id' => 'lvs-1', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A],
			['id' => 'lvs-2', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A],
			['id' => 'lvs-3', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A],
		];

		$step->run($this->repairOutput());

		$profileReads = array_filter(
			$this->store->reads,
			static fn (array $read): bool => ($read['config']['filters']['schema'] ?? '') === 'learner-profile'
		);
		$lvsReads = array_values(
			array_filter(
				$this->store->reads,
				static fn (array $read): bool => ($read['config']['filters']['schema'] ?? '') === 'lvs-result'
			)
		);
		self::assertCount(1, $profileReads);
		self::assertFalse($lvsReads[0]['rbac']);
		self::assertFalse($lvsReads[0]['multitenancy']);
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
			$this->store->rows['lvs-result'][] = ['id' => 'lvs-' . $i, 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A];
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

	/**
	 * The step is listed for post-migration, so an upgrade runs it.
	 *
	 * @return void
	 */
	public function testTheStepRunsOnUpgrade(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertNotFalse($info);

		$steps = array_map('strval', $info->xpath('//repair-steps/post-migration/step'));
		self::assertContains(BackfillLvsResultLearnerRef::class, $steps);
	}//end testTheStepRunsOnUpgrade()
}//end class
