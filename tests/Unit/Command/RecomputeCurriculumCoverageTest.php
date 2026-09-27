<?php

/**
 * Unit tests for the `learniq:curriculum-coverage:recompute` occ command
 * (curriculum-coverage-rollup).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/curriculum-coverage-rollup/tasks.md#task-5-occ-learniqcurriculum-coveragerecompute
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Command;

use OCA\Learniq\Command\RecomputeCurriculumCoverage;
use OCA\Learniq\Service\CurriculumCoverageRollup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the command through Symfony's CommandTester against a rollup double.
 */
class RecomputeCurriculumCoverageTest extends TestCase {

	/**
	 * Frameworks recompute() was called with.
	 *
	 * @var array<int, string>
	 */
	private array $recomputed = [];

	/**
	 * A rollup double knowing three frameworks.
	 *
	 * @return CurriculumCoverageRollup&MockObject
	 */
	private function rollup(): CurriculumCoverageRollup&MockObject {
		$rollup = $this->createMock(CurriculumCoverageRollup::class);
		$rollup->method('allFrameworkIds')->willReturn(['fw-a', 'fw-b', 'fw-c']);
		$rollup->method('frameworkExists')->willReturnCallback(static fn (string $id): bool => in_array($id, ['fw-a', 'fw-b', 'fw-c'], true));
		$rollup->method('recompute')->willReturnCallback(
			function (string $frameworkId): array {
				$this->recomputed[] = $frameworkId;
				return ['saved' => 2, 'deleted' => 0, 'unchanged' => 1];
			}
		);
		return $rollup;
	}//end rollup()

	/**
	 * Without an option every framework is recomputed and counted.
	 *
	 * @return void
	 */
	public function testRecomputesEveryFramework(): void {
		$tester = new CommandTester(new RecomputeCurriculumCoverage($this->rollup()));

		$exit = $tester->execute([]);

		self::assertSame(0, $exit);
		self::assertSame(['fw-a', 'fw-b', 'fw-c'], $this->recomputed);
		self::assertStringContainsString('Recomputed 3 framework(s).', $tester->getDisplay());
		self::assertStringContainsString('fw-b: 2 saved, 0 deleted, 1 unchanged', $tester->getDisplay());

	}//end testRecomputesEveryFramework()

	/**
	 * With --framework only that framework is recomputed.
	 *
	 * @return void
	 */
	public function testRecomputesOneFramework(): void {
		$tester = new CommandTester(new RecomputeCurriculumCoverage($this->rollup()));

		$exit = $tester->execute(['--framework' => 'fw-b']);

		self::assertSame(0, $exit);
		self::assertSame(['fw-b'], $this->recomputed);
		self::assertStringContainsString('Recomputed 1 framework(s).', $tester->getDisplay());

	}//end testRecomputesOneFramework()

	/**
	 * An unknown framework exits 1 and recomputes nothing.
	 *
	 * @return void
	 */
	public function testUnknownFrameworkExitsOne(): void {
		$tester = new CommandTester(new RecomputeCurriculumCoverage($this->rollup()));

		$exit = $tester->execute(['--framework' => 'nope']);

		self::assertSame(1, $exit);
		self::assertSame([], $this->recomputed);
		self::assertStringContainsString('No framework with id nope.', $tester->getDisplay());

	}//end testUnknownFrameworkExitsOne()

	/**
	 * The command is named for occ and registered in info.xml.
	 *
	 * @return void
	 */
	public function testCommandIsNamedAndRegistered(): void {
		$command = new RecomputeCurriculumCoverage($this->rollup());
		$info    = (string) file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		self::assertSame('learniq:curriculum-coverage:recompute', $command->getName());
		self::assertStringContainsString('<command>OCA\\Learniq\\Command\\RecomputeCurriculumCoverage</command>', $info);

	}//end testCommandIsNamedAndRegistered()
}//end class
