<?php

/**
 * Learniq PortalMessageContacts read-failure tests
 *
 * Pins whom a resident may write to when the reads behind it fail or find
 * nothing current: an enrolment that cannot be read, an empty id, a group
 * that has ended, a cohort read that fails (logged), and a staff read that
 * fails (the teacher then reads as Teacher). Entity rows read like arrays.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
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

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalMessageContacts;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * PortalMessageContacts when its reads fail.
 */
#[CoversClass(PortalMessageContacts::class)]
class PortalMessageContactsReadFailuresTest extends TestCase {

	/**
	 * A store answering `find` from rows by schema and id (null when absent).
	 *
	 * @param array<string, array<string, array<string, mixed>>> $rows By schema, by id.
	 *
	 * @return ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function store(array $rows): ObjectService {
		$store = $this->createMock(ObjectService::class);
		$store->method('find')->willReturnCallback(
			static function (string|int $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use ($rows): ?ObjectEntity {
				$row = ($rows[(string)$schema][(string)$id] ?? null);
				return $row === null ? null : OrEntityFactory::make($row, (string)$schema, 'learniq', (string)$id);
			}
		);
		return $store;
	}//end store()

	/**
	 * An unreadable enrolment, an empty id and an ended group give nobody.
	 *
	 * @return void
	 */
	public function testNoCurrentGroupGivesNobody(): void {
		$store = $this->store(
			[
				'enrolment' => [
					'enr-past' => ['lifecycle' => 'active', 'cohortId' => 'c-past'],
					'enr-gone' => ['lifecycle' => 'active', 'cohortId' => 'c-gone'],
				],
				'cohort'    => [
					'c-past' => ['lifecycle' => 'completed', 'teacherIds' => ['anna']],
				],
			]
		);
		$store->expects(self::never())->method('findAll');
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::never())->method('getDisplayName');
		$contacts = new PortalMessageContacts($store, $users, $this->createMock(LoggerInterface::class));

		self::assertSame([], $contacts->ownContacts(enrolmentId: 'enr-past'));
		self::assertSame([], $contacts->ownContacts(enrolmentId: 'enr-gone'));
		self::assertSame([], $contacts->ownContacts(enrolmentId: ''));
		self::assertSame([], $contacts->childContacts(profileId: ''));
	}//end testNoCurrentGroupGivesNobody()

	/**
	 * A find that throws counts as not found.
	 *
	 * @return void
	 */
	public function testAFindThatThrowsGivesNobody(): void {
		$store = $this->createMock(ObjectService::class);
		$store->method('find')->willThrowException(new RuntimeException('db down'));
		$store->expects(self::never())->method('findAll');

		$contacts = new PortalMessageContacts($store, $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));

		self::assertSame([], $contacts->ownContacts(enrolmentId: 'enr-1'));
		self::assertSame([], $contacts->childContacts(profileId: 'lp-1'));
	}//end testAFindThatThrowsGivesNobody()

	/**
	 * A cohort read that fails is logged with its schema and gives nobody.
	 *
	 * @return void
	 */
	public function testAFailedCohortReadIsLogged(): void {
		$store = $this->store(['learner-profile' => ['lp-1' => ['ncUserId' => 'kid']]]);
		$store->method('findAll')->willThrowException(new RuntimeException('index gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Learniq: portal message contacts read failed',
			['schema' => 'cohort', 'reason' => 'index gone']
		);

		$contacts = new PortalMessageContacts($store, $this->createMock(IUserManager::class), $logger);

		self::assertSame([], $contacts->childContacts(profileId: 'lp-1'));
	}//end testAFailedCohortReadIsLogged()

	/**
	 * Cohorts answered as entities count; a staff read that fails leaves the role Teacher, in the portal's language.
	 *
	 * @return void
	 */
	public function testEntityCohortsAndAFailedStaffRead(): void {
		$store = $this->store(['learner-profile' => ['lp-1' => ['ncUserId' => 'kid']]]);
		$store->method('findAll')->willReturnCallback(
			static function (array $config): array {
				if ($config['filters']['schema'] === 'staff') {
					throw new RuntimeException('staff gone');
				}

				return [
					OrEntityFactory::make(['lifecycle' => 'active', 'learnerIds' => ['kid'], 'teacherIds' => ['anna']], 'cohort', 'learniq', 'c-1'),
					'not a row',
					['lifecycle' => 'archived', 'learnerIds' => ['kid'], 'teacherIds' => ['old']],
				];
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['anna', 'Anna de Wit'], ['old', 'Oud']]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => ['Teacher' => 'Leerkracht'][$text] ?? $text);

		$contacts = new PortalMessageContacts($store, $users, $this->createMock(LoggerInterface::class), $l10n);

		self::assertSame(
			[['staffRef' => 'anna', 'name' => 'Anna de Wit', 'role' => 'Leerkracht']],
			$contacts->childContacts(profileId: 'lp-1')
		);
	}//end testEntityCohortsAndAFailedStaffRead()
}//end class
