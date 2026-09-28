<?php

/**
 * Unit tests for ApplyStoreRightsDefaults: the D27 store rights reach an
 * existing instance exactly once, and never override an administrator's choice.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-existing-installs-get-the-new-store-defaults-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\ApplyStoreRightsDefaults;
use OCA\Learniq\Service\ActionAuthService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Repair\ApplyStoreRightsDefaults
 */
class ApplyStoreRightsDefaultsTest extends TestCase {

	/**
	 * App config values the step wrote.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The matrix the step wrote, or null when it wrote none.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $written = null;

	/**
	 * Build the step over a matrix and a marker, with the shipped seed file.
	 *
	 * @param array<string, array<int, string>> $matrix   The current matrix.
	 * @param string                            $marker   The stored marker value.
	 * @param string|null                       $seedPath A seed file, or null for the shipped one.
	 *
	 * @return ApplyStoreRightsDefaults
	 */
	private function step(array $matrix, string $marker='', ?string $seedPath=null): ApplyStoreRightsDefaults {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('getMatrix')->willReturn($matrix);
		$actionAuth->method('setMatrix')->willReturnCallback(
			function (array $matrix): void {
				$this->written = $matrix;
			}
		);

		$this->config = ['store_rights_defaults_applied' => $marker];
		$appConfig    = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		if ($seedPath === null) {
			return new ApplyStoreRightsDefaults($actionAuth, $appConfig);
		}

		return new ApplyStoreRightsDefaults($actionAuth, $appConfig, $seedPath);
	}//end step()

	/**
	 * An untouched instance gets both defaults and the marker.
	 *
	 * @return void
	 */
	public function testAnUntouchedInstanceGetsBothDefaults(): void {
		$this->step(['course-package.share' => ['admin'], 'qti.import' => ['admin']])->run($this->createMock(IOutput::class));

		self::assertSame(['admin', 'instructors', 'team-leads'], $this->written['course-store.install']);
		self::assertSame(['admin', 'team-leads'], $this->written['course-package.share']);
		self::assertSame(['admin'], $this->written['qti.import'], 'other rows are untouched');
		self::assertSame('1', $this->config['store_rights_defaults_applied']);
	}//end testAnUntouchedInstanceGetsBothDefaults()

	/**
	 * A share row the administrator changed stays; the install row is still added.
	 *
	 * @return void
	 */
	public function testACustomisedShareRowIsLeftAlone(): void {
		$this->step(['course-package.share' => ['admin', 'coordinators']])->run($this->createMock(IOutput::class));

		self::assertSame(['admin', 'coordinators'], $this->written['course-package.share']);
		self::assertSame(['admin', 'instructors', 'team-leads'], $this->written['course-store.install']);
	}//end testACustomisedShareRowIsLeftAlone()

	/**
	 * After the step ran once, an administrator's narrowing survives upgrades.
	 *
	 * @return void
	 */
	public function testTheStepRunsOnlyOnce(): void {
		$this->step(['course-package.share' => ['admin']], '1')->run($this->createMock(IOutput::class));

		self::assertNull($this->written);
	}//end testTheStepRunsOnlyOnce()

	/**
	 * An install row the administrator already set stays as it is.
	 *
	 * @return void
	 */
	public function testAnExistingInstallRowIsKept(): void {
		$this->step(['course-store.install' => ['admin'], 'course-package.share' => ['admin', 'team-leads']])->run($this->createMock(IOutput::class));

		self::assertNull($this->written, 'nothing to change, so nothing written');
		self::assertSame('1', $this->config['store_rights_defaults_applied']);
	}//end testAnExistingInstallRowIsKept()

	/**
	 * An empty matrix belongs to the seed: nothing written, no marker, so the
	 * step still runs after the seed does.
	 *
	 * @return void
	 */
	public function testAnEmptyMatrixIsLeftToTheSeed(): void {
		$this->step([])->run($this->createMock(IOutput::class));

		self::assertNull($this->written);
		self::assertSame('', $this->config['store_rights_defaults_applied']);
	}//end testAnEmptyMatrixIsLeftToTheSeed()

	/**
	 * Without a readable seed nothing changes and the step stays pending.
	 *
	 * @return void
	 */
	public function testAMissingSeedChangesNothing(): void {
		$this->step(['course-package.share' => ['admin']], '', '/nonexistent/actions.seed.json')->run($this->createMock(IOutput::class));

		self::assertNull($this->written);
		self::assertSame('', $this->config['store_rights_defaults_applied']);
	}//end testAMissingSeedChangesNothing()
}//end class
