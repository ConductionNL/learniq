<?php

/**
 * Learniq SharedCodeFilter unit tests.
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

use OCA\Learniq\Service\SharedCodeFilter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A second example set leaves out the regulation codes the first one created.
 */
class SharedCodeFilterTest extends TestCase {

	/**
	 * A filter over an object service double that holds `$rows` per schema.
	 *
	 * @param array<int, array<string, mixed>> $rows  The stored rows.
	 * @param bool                             $fails Whether the read throws.
	 *
	 * @return SharedCodeFilter
	 */
	private function filter(array $rows, bool $fails=false): SharedCodeFilter {
		$objects   = new class($rows, $fails) {
			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $rows  The rows.
			 * @param bool                             $fails Whether to throw.
			 */
			public function __construct(private readonly array $rows, private readonly bool $fails) {
			}//end __construct()

			/**
			 * Answer like ObjectService::findAll().
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Tenancy flag.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				if ($this->fails === true) {
					throw new RuntimeException('no database');
				}

				return $this->rows;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		return new SharedCodeFilter($container, $this->createMock(LoggerInterface::class));
	}//end filter()

	/**
	 * The shipped descriptor of one set.
	 *
	 * @param string $set The set id.
	 *
	 * @return array<string, mixed>
	 */
	private static function descriptor(string $set): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/' . $set . '.json'), true);
	}//end descriptor()

	/**
	 * Loading training after the company set leaves out exactly VCA and NIS2,
	 * the two codes both sets ship, and nothing outside the regulation bucket.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-the-training-set-after-the-company-set
	 */
	public function testTrainingAfterTheCompanySetSkipsVcaAndNis2(): void {
		$company  = self::descriptor(set: 'corporate');
		$training = self::descriptor(set: 'training');
		$stored   = array_map(
			static fn (array $row): array => ['slug' => $row['slug'], '@self' => ['id' => $row['uuid']]],
			$company['x-openregister']['seedData']['objects']['regulation']
		);

		$filtered = $this->filter(rows: $stored)->withoutCodesHeldElsewhere(data: $training);

		$before = array_column($training['x-openregister']['seedData']['objects']['regulation'], 'slug');
		$after  = array_column($filtered['x-openregister']['seedData']['objects']['regulation'], 'slug');
		self::assertSame(['VCA', 'NIS2'], array_values(array_diff($before, $after)));
		self::assertContains('ARBOWET-BHV', $after);

		$rest = $filtered['x-openregister']['seedData']['objects'];
		unset($rest['regulation']);
		$original = $training['x-openregister']['seedData']['objects'];
		unset($original['regulation']);
		self::assertSame($original, $rest);
	}//end testTrainingAfterTheCompanySetSkipsVcaAndNis2()

	/**
	 * Loading the same set again keeps its rows, so a new version still
	 * updates them; a row the object service returns as an entity counts too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-loading-the-same-set-again
	 */
	public function testTheSameSetAgainKeepsItsRows(): void {
		$training = self::descriptor(set: 'training');
		$stored   = array_map(
			static fn (array $row): array => ['slug' => $row['slug'], 'id' => $row['uuid']],
			$training['x-openregister']['seedData']['objects']['regulation']
		);

		$filtered = $this->filter(rows: $stored)->withoutCodesHeldElsewhere(data: $training);

		self::assertSame(
			$training['x-openregister']['seedData']['objects']['regulation'],
			$filtered['x-openregister']['seedData']['objects']['regulation']
		);
	}//end testTheSameSetAgainKeepsItsRows()

	/**
	 * A read that fails keeps every row: the load goes on as before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-example-set-does-not-duplicate-a-regulation-code
	 */
	public function testAFailedReadKeepsEveryRow(): void {
		$training = self::descriptor(set: 'training');

		self::assertSame($training, $this->filter(rows: [], fails: true)->withoutCodesHeldElsewhere(data: $training));
	}//end testAFailedReadKeepsEveryRow()
}//end class
