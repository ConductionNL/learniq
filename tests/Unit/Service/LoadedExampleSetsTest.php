<?php

/**
 * Learniq LoadedExampleSets unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LoadedExampleSets;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The loaded-set list the wizard builds its removal steps from.
 */
class LoadedExampleSetsTest extends TestCase {

	/**
	 * A list over an in-memory app config holding `$value`.
	 *
	 * @param string       $value   The stored value.
	 * @param \ArrayObject $written Receives each write.
	 *
	 * @return LoadedExampleSets
	 */
	private function sets(string $value, \ArrayObject $written=new \ArrayObject()): LoadedExampleSets {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (): string => ($written['value'] ?? $value)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $stored) use ($written): bool {
				$written['value'] = $stored;
				return true;
			}
		);

		return new LoadedExampleSets($appConfig);
	}//end sets()

	/**
	 * A broken or foreign value reads as no sets, and bad entries are skipped.
	 *
	 * @return void
	 */
	public function testAMalformedValueReadsAsNoSets(): void {
		self::assertSame([], $this->sets(value: '')->all());
		self::assertSame([], $this->sets(value: 'not json')->all());
		self::assertSame(
			[['id' => 'po', 'label' => 'po']],
			$this->sets(value: '[{"id":"po"},{"label":"x"},3,{"id":""}]')->all()
		);
	}//end testAMalformedValueReadsAsNoSets()

	/**
	 * Loading a set twice keeps one entry, moved to the end; forgetting drops it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
	 */
	public function testRecordKeepsOneEntryAndForgetDropsIt(): void {
		$written = new \ArrayObject();
		$sets    = $this->sets(value: '[{"id":"po","label":"Primary school"},{"id":"vo","label":"Secondary school"}]', written: $written);

		$sets->record(setId: 'po', label: 'Primary school');
		self::assertSame(['vo', 'po'], array_column($sets->all(), 'id'));

		$sets->forget(setId: 'vo');
		self::assertSame(['po'], array_column($sets->all(), 'id'));
	}//end testRecordKeepsOneEntryAndForgetDropsIt()

	/**
	 * Every set the wizard offers gets a removal step reported done; only
	 * `remove-example-set-<id>` names a set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-removal-step-never-runs-by-itself
	 */
	public function testRemovalStepsAndActionIds(): void {
		$sets = $this->sets(value: '');

		self::assertSame(
			['remove-example-set-po' => ['done' => true], 'remove-example-set-demo' => ['done' => true]],
			$sets->removalSteps(choices: [['id' => 'none'], ['id' => 'po'], ['id' => 'demo'], ['label' => 'x']])
		);
		self::assertSame('corporate', $sets->setIdFromAction(actionId: 'remove-example-set-corporate'));
		self::assertNull($sets->setIdFromAction(actionId: 'remove-example-set'));
		self::assertNull($sets->setIdFromAction(actionId: 'remove-example-set-'));
		self::assertNull($sets->setIdFromAction(actionId: 'load-example-set'));
	}//end testRemovalStepsAndActionIds()

	/**
	 * Only a clean removal of a recorded import forgets the set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-removing-the-company-set
	 */
	public function testOnlyACleanRemovalForgetsTheSet(): void {
		$written = new \ArrayObject();
		$sets    = $this->sets(value: '[{"id":"po","label":"Primary school"}]', written: $written);

		$sets->forgetIfRemoved(setId: 'po', answer: ['errors' => 1, 'jobs' => ['job-1']]);
		$sets->forgetIfRemoved(setId: 'po', answer: ['errors' => 0, 'jobs' => []]);
		self::assertSame(['po'], array_column($sets->all(), 'id'));

		$sets->forgetIfRemoved(setId: 'po', answer: ['errors' => 0, 'jobs' => ['job-1']]);
		self::assertSame([], $sets->all());
	}//end testOnlyACleanRemovalForgetsTheSet()
}//end class
