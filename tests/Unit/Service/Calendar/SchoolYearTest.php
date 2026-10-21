<?php

/**
 * Learniq SchoolYear and StandbySlotDefaultsStamp unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Calendar
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
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Calendar;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\StandbySlotDefaultsStamp;
use OCA\Learniq\Service\Calendar\SchoolYear;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the school year setting and its standby slot default.
 */
class SchoolYearTest extends TestCase {

	/**
	 * A SchoolYear over a stored setting.
	 *
	 * @param string|null $start The stored `school_year_start`, null for none.
	 *
	 * @return SchoolYear
	 */
	private function year(?string $start): SchoolYear {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($start ?? $default)
		);

		return new SchoolYear(appConfig: $config);
	}//end year()

	/**
	 * Without a setting the year runs from 1 August to 31 July.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#scenario-no-setting
	 */
	public function testWithoutASettingTheYearStartsOnTheFirstOfAugust(): void {
		$year = $this->year(start: null);

		self::assertSame('08-01', $year->start());
		self::assertSame('2026-2027', $year->yearOf(date: '2026-10-21'));
		self::assertSame('2025-2026', $year->yearOf(date: '2026-07-31'));
		self::assertSame(['2026-08-01', '2027-07-31'], $year->around(date: '2026-10-21'));
		self::assertSame('08-01', (new SchoolYear())->start());
	}//end testWithoutASettingTheYearStartsOnTheFirstOfAugust()

	/**
	 * A school that starts in September counts late August in the old year.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#scenario-a-school-that-starts-in-september
	 */
	public function testASchoolThatStartsInSeptember(): void {
		$year = $this->year(start: '09-01');

		self::assertSame('2026-2027', $year->yearOf(date: '2027-08-20'));
		self::assertSame(['2026-09-01', '2027-08-31'], $year->bounds(academicYear: '2026-2027'));
	}//end testASchoolThatStartsInSeptember()

	/**
	 * An unusable setting reads as 1 August.
	 *
	 * @return void
	 */
	public function testAnUnusableSettingReadsAsTheFirstOfAugust(): void {
		self::assertSame('08-01', $this->year(start: '13-40')->start());
		self::assertSame('08-01', $this->year(start: 'september')->start());
	}//end testAnUnusableSettingReadsAsTheFirstOfAugust()

	/**
	 * A standby slot without dates or tenant gets the school year and the
	 * planner's tenant; values the client sends are kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#scenario-no-setting
	 */
	public function testAStandbySlotGetsTheSchoolYearAndTheTenant(): void {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('standby-slot');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1792573200);
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$tenants = $this->createMock(CallerTenantResolver::class);
		$tenants->method('resolve')->willReturn('tenant-1');
		$stamp = new StandbySlotDefaultsStamp(schemaResolver: $resolver, schoolYear: $this->year(start: null), timeFactory: $time, userSession: $session, tenants: $tenants);

		$bare = new ObjectCreatingEvent(OrEntityFactory::make(['teacherId' => 't-1', 'weekday' => 'monday', 'startsAt' => '08:00', 'endsAt' => '09:00'], 'standby-slot'));
		$stamp->handle($bare);
		self::assertSame(['validFrom' => '2026-08-01', 'validUntil' => '2027-07-31', 'tenant_id' => 'tenant-1'], $bare->getModifiedData());

		$given = new ObjectCreatingEvent(OrEntityFactory::make(['teacherId' => 't-1', 'validFrom' => '2026-11-01', 'validUntil' => '2026-12-31', 'tenant_id' => 'tenant-2'], 'standby-slot'));
		$stamp->handle($given);
		self::assertSame([], $given->getModifiedData());
	}//end testAStandbySlotGetsTheSchoolYearAndTheTenant()

	/**
	 * The stamp is wired on a create.
	 *
	 * @return void
	 */
	public function testTheStampIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . StandbySlotDefaultsStamp::class, $registered);
	}//end testTheStampIsRegistered()
}//end class
