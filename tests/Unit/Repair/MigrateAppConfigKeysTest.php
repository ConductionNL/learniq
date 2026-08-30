<?php

/**
 * Unit tests for MigrateAppConfigKeys.
 *
 * The reserved-key case is the important one, and it is a regression test for
 * a defect that shipped: the step originally copied EVERY key it found under
 * the old app-config namespace, including Nextcloud's own `enabled`.
 * `AppManager::enableApp()` writes `enabled` through the deprecated
 * `IAppConfig::setValue()`, which stores type MIXED; copying the old value
 * with `setValueString()` stores type STRING; and the next `app:enable` then
 * throws `AppConfigTypeConflictException: conflict between new type (mixed)
 * and old type (string)`. That failure happens BEFORE the app can run anything
 * that would repair it, so the app became permanently un-enableable. Observed
 * on a live instance 2026-08-19; the rows had to be deleted by hand.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Repair\MigrateAppConfigKeys
 */
class MigrateAppConfigKeysTest extends TestCase {
	/**
	 * Old app-config namespace, as the step reads it.
	 */
	private const OLD_APP_ID = 'scholiq';

	/**
	 * New app-config namespace, as the step writes it.
	 */
	private const NEW_APP_ID = 'learniq';

	/**
	 * Build the step over an IAppConfig double backed by two in-memory maps.
	 *
	 * @param array<string, string> $old   Stored values under the old namespace.
	 * @param array<string, string> $new   Stored values already under the new namespace.
	 * @param array<string, string> $wrote Receives every key the step writes.
	 *
	 * @return MigrateAppConfigKeys
	 */
	private function stepWith(array $old, array $new, array &$wrote): MigrateAppConfigKeys {
		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('getKeys')->willReturnCallback(
			static function (string $app) use ($old): array {
				return $app === self::OLD_APP_ID ? array_keys($old) : [];
			}
		);

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($old, $new): string {
				if ($app === self::OLD_APP_ID) {
					return $old[$key] ?? $default;
				}

				return $new[$key] ?? $default;
			}
		);

		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$wrote): bool {
				$wrote[$app . '.' . $key] = $value;
				return true;
			}
		);

		return new MigrateAppConfigKeys($appConfig, $this->createMock(LoggerInterface::class));
	}//end stepWith()

	/**
	 * Nextcloud's own keys MUST NOT be copied. This is the regression guard.
	 *
	 * @return void
	 */
	public function testReservedNextcloudKeysAreNeverCopied(): void {
		$wrote = [];
		$step = $this->stepWith(
			[
				'enabled' => 'yes',
				'installed_version' => '0.2.13',
				'types' => '',
				'actions' => '{"qti.import":["admin"]}',
			],
			[],
			$wrote
		);

		$step->run($this->createMock(IOutput::class));

		self::assertArrayNotHasKey(
			self::NEW_APP_ID . '.enabled',
			$wrote,
			'Copying `enabled` makes the app permanently un-enableable — AppManager writes it as MIXED, this would store STRING.'
		);
		self::assertArrayNotHasKey(self::NEW_APP_ID . '.installed_version', $wrote);
		self::assertArrayNotHasKey(self::NEW_APP_ID . '.types', $wrote);
	}//end testReservedNextcloudKeysAreNeverCopied()

	/**
	 * The app's own keys ARE copied — the positive control, so the test above
	 * cannot pass simply because the step wrote nothing at all.
	 *
	 * @return void
	 */
	public function testAppOwnedKeysAreCopied(): void {
		$wrote = [];
		$step = $this->stepWith(
			['enabled' => 'yes', 'actions' => '{"qti.import":["admin"]}'],
			[],
			$wrote
		);

		$step->run($this->createMock(IOutput::class));

		self::assertSame(
			'{"qti.import":["admin"]}',
			$wrote[self::NEW_APP_ID . '.actions'] ?? null,
			'The ADR-023 action matrix is stored data: losing it silently resets every action to its default.'
		);
	}//end testAppOwnedKeysAreCopied()

	/**
	 * A value already present under the new namespace is never overwritten.
	 *
	 * @return void
	 */
	public function testExistingTargetValueIsNotOverwritten(): void {
		$wrote = [];
		$step = $this->stepWith(
			['actions' => '{"from":"old"}'],
			['actions' => '{"from":"new"}'],
			$wrote
		);

		$step->run($this->createMock(IOutput::class));

		self::assertArrayNotHasKey(
			self::NEW_APP_ID . '.actions',
			$wrote,
			'An administrator customisation under the new id must win over the pre-rename copy.'
		);
	}//end testExistingTargetValueIsNotOverwritten()

	/**
	 * An empty source value is skipped rather than written as an empty string.
	 *
	 * @return void
	 */
	public function testEmptySourceValueIsSkipped(): void {
		$wrote = [];
		$step = $this->stepWith(['actions' => ''], [], $wrote);

		$step->run($this->createMock(IOutput::class));

		self::assertSame([], $wrote);
	}//end testEmptySourceValueIsSkipped()

	/**
	 * With nothing stored under the old namespace the step is a no-op and says so.
	 *
	 * @return void
	 */
	public function testNoOldKeysIsAnAnnouncedNoop(): void {
		$wrote = [];
		$step = $this->stepWith([], [], $wrote);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())
			->method('info')
			->with(self::stringContains('nothing to do'));

		$step->run($output);

		self::assertSame([], $wrote);
	}//end testNoOldKeysIsAnAnnouncedNoop()

	/**
	 * The step names itself, so a repair run is readable.
	 *
	 * @return void
	 */
	public function testGetNameMentionsBothNamespaces(): void {
		$wrote = [];
		$name = $this->stepWith([], [], $wrote)->getName();

		self::assertStringContainsString(self::OLD_APP_ID, $name);
		self::assertStringContainsString(self::NEW_APP_ID, $name);
	}//end testGetNameMentionsBothNamespaces()
}//end class
