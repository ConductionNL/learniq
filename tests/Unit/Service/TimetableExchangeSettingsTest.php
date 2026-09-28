<?php

/**
 * Learniq TimetableExchangeSettings unit tests.
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
 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\TimetableExchangeSettings;
use OCA\Learniq\Timetabling\PlanninqTimetableImport;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * The group code maps per rostering system and the SWV receiver.
 */
class TimetableExchangeSettingsTest extends TestCase {

	/**
	 * Settings over an in-memory app config.
	 *
	 * @param array<string, string> $stored Initial values.
	 *
	 * @return array{0: TimetableExchangeSettings, 1: \ArrayObject<string, string>}
	 */
	private function settings(array $stored=[]): array {
		$values    = new \ArrayObject($stored);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($values[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($values): bool {
				$values[$key] = $value;
				return true;
			}
		);

		return [new TimetableExchangeSettings($appConfig), $values];
	}//end settings()

	/**
	 * The sources are exactly the ones the import delivers from.
	 *
	 * @return void
	 */
	public function testTheSourcesAreTheImportsSources(): void {
		$vendors = (new ReflectionClassConstant(PlanninqTimetableImport::class, 'VENDOR_SOURCES'))->getValue();

		self::assertSame(array_values($vendors), TimetableExchangeSettings::ROSTER_SOURCES);
	}//end testTheSourcesAreTheImportsSources()

	/**
	 * Saved maps read back per source, empty rows and unknown sources dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#scenario-saving-a-map-for-zermelo
	 */
	public function testSavedMapsReadBackPerSource(): void {
		[$settings, $values] = $this->settings();

		$settings->save(
			groupMaps: ['roster-zermelo' => ['4H1' => 'cohort-1', ' ' => 'cohort-2', '4H2' => ''], 'roster-xedule' => []],
			receiverId: 'swv-kindkans'
		);

		self::assertSame(['4H1' => 'cohort-1'], $settings->groupMapFor(source: 'roster-zermelo'));
		self::assertSame([], $settings->groupMapFor(source: 'roster-xedule'));
		self::assertSame(['roster-zermelo', 'roster-untis-oneroster', 'roster-xedule', 'roster-timeedit'], array_keys($settings->groupMaps()));
		self::assertSame('swv-kindkans', $settings->swvReceiverId());
		self::assertSame('swv-kindkans', $values['swv_receiver_id']);
	}//end testSavedMapsReadBackPerSource()

	/**
	 * A malformed receiver, a non-list and an unknown system are refused.
	 *
	 * @return void
	 */
	public function testValidationNamesWhatIsWrong(): void {
		[$settings] = $this->settings();

		self::assertNull($settings->validate(groupMaps: ['roster-zermelo' => []], receiverId: ''));
		self::assertStringContainsString('SWV receiver', (string)$settings->validate(groupMaps: [], receiverId: 'SWV Kindkans'));
		self::assertStringContainsString('list', (string)$settings->validate(groupMaps: 'x', receiverId: ''));
		self::assertStringContainsString('roster-magister', (string)$settings->validate(groupMaps: ['roster-magister' => []], receiverId: ''));
	}//end testValidationNamesWhatIsWrong()

	/**
	 * A broken stored value reads as empty maps.
	 *
	 * @return void
	 */
	public function testABrokenValueReadsAsEmpty(): void {
		[$settings] = $this->settings(stored: ['timetable_group_maps' => 'not json']);

		self::assertSame([], $settings->groupMapFor(source: 'roster-zermelo'));
	}//end testABrokenValueReadsAsEmpty()
}//end class
