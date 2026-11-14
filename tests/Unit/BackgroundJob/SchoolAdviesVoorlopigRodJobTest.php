<?php

/**
 * Learniq school advies voorlopig ROD job unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
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

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\SchoolAdviesVoorlopigRodJob;
use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Service\SchoolAdviesRodTiming;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\BackgroundJob\ActorForwarding;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

// The unit bootstrap does not load every support helper.
require_once __DIR__ . '/../../Support/CapturingLogger.php';

/**
 * The job requests one ROD export per due voorlopig advice and stores the job id on it.
 *
 * @covers \OCA\Learniq\BackgroundJob\SchoolAdviesVoorlopigRodJob
 * @uses   \OCA\Learniq\BackgroundJob\ForwardedJob
 * @uses   \OCA\Learniq\BackgroundJob\ActorForwarding
 * @uses   \OCA\Learniq\Service\SchoolAdviesRodTiming
 */
class SchoolAdviesVoorlopigRodJobTest extends TestCase {

	/**
	 * The forwarding bundle over mocked Nextcloud services.
	 *
	 * @return ActorForwarding
	 */
	private function forwarding(): ActorForwarding {
		return new ActorForwarding(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			organisation: $this->createMock(OrganisationService::class)
		);
	}//end forwarding()

	/**
	 * Run the job's protected runDeferred() over entries.
	 *
	 * @param object                          $job     The job.
	 * @param list<array<string, mixed>|null> $entries The deferred entries.
	 *
	 * @return void
	 */
	private function runDeferred(object $job, array $entries): void {
		(new ReflectionMethod($job, 'runDeferred'))->invoke($job, new DeferredListenerContext(userId: 'teacher-1', orgUuid: null, entries: $entries));
	}//end runDeferred()

	/**
	 * A due voorlopig advice.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function advies(array $extra = []): array {
		return array_merge(
			['voorlopigAdviesLevel' => 'havo', 'voorlopigAdviesDate' => '2026-01-15', 'learnerId' => 'lp-1', 'lifecycle' => 'voorlopig', 'tenant_id' => 'school-7'],
			$extra
		);
	}//end advies()

	/**
	 * The job over an ObjectService that finds the given rows by id.
	 *
	 * @param array<string, array<string, mixed>> $rows        Advice rows by id.
	 * @param ObjectService                       $objects     The object service mock.
	 * @param IntegriqExchangeClient              $integriq    The exchange client mock.
	 * @param CapturingLogger                     $logger      The logger.
	 *
	 * @return SchoolAdviesVoorlopigRodJob
	 */
	private function job(array $rows, ObjectService $objects, IntegriqExchangeClient $integriq, CapturingLogger $logger): SchoolAdviesVoorlopigRodJob {
		$objects->method('find')->willReturnCallback(
			static fn (int|string $id): ?\OCA\OpenRegister\Db\ObjectEntity => isset($rows[$id]) === true ? OrEntityFactory::make(array_merge(['id' => $id], $rows[$id]), 'school-advies') : null
		);

		return new SchoolAdviesVoorlopigRodJob($this->forwarding(), $logger, $objects, $integriq, new SchoolAdviesRodTiming());
	}//end job()

	/**
	 * A due advice requests the ROD export with its scope and stores the job id on the advice.
	 *
	 * @return void
	 */
	public function testADueAdviceRequestsTheExportAndStoresTheJobId(): void {
		$objects = $this->createMock(ObjectService::class);
		$integriq = $this->createMock(IntegriqExchangeClient::class);
		$integriq->expects(self::once())->method('requestJob')->with(
			'bron-rod',
			'export',
			'school-advies/sa-1',
			['schema' => 'school-advies', 'recordIds' => ['sa-1'], 'tenantId' => 'school-7', 'berichtsoort' => 'schooladvies'],
			ExchangeDisclosure::ROD_SCHOOL_ADVICE_MAPPING,
			'system',
			'ROD voorlopig schooladvies'
		)->willReturn('job-42');
		$objects->expects(self::once())->method('saveObject')->with(
			self::callback(static fn (array $object): bool => $object['voorlopigExchangeJobId'] === 'job-42' && $object['voorlopigAdviesLevel'] === 'havo'),
			self::anything(),
			'learniq',
			'school-advies',
			'sa-1'
		);
		$logger = new CapturingLogger();

		$this->runDeferred(job: $this->job(['sa-1' => self::advies()], $objects, $integriq, $logger), entries: [['schoolAdviesId' => 'sa-1']]);

		self::assertSame([], $logger->records);
	}//end testADueAdviceRequestsTheExportAndStoresTheJobId()

	/**
	 * Entries without an id, unknown advices and advices that are not due request nothing.
	 *
	 * @return void
	 */
	public function testNothingIsRequestedForMissingOrNotDueAdvice(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('saveObject');
		$integriq = $this->createMock(IntegriqExchangeClient::class);
		$integriq->expects(self::never())->method('requestJob');
		$logger = new CapturingLogger();

		$rows = [
			'sent' => self::advies(['voorlopigExchangeJobId' => 'job-1']),
			'final' => self::advies(['lifecycle' => 'definitief']),
			'no-level' => self::advies(['voorlopigAdviesLevel' => '']),
		];
		$this->runDeferred(
			job: $this->job($rows, $objects, $integriq, $logger),
			entries: [[], ['schoolAdviesId' => ''], ['schoolAdviesId' => 'missing'], ['schoolAdviesId' => 'sent'], ['schoolAdviesId' => 'final'], ['schoolAdviesId' => 'no-level']]
		);

		self::assertSame([], $logger->records);
	}//end testNothingIsRequestedForMissingOrNotDueAdvice()

	/**
	 * A failing exchange is logged per advice and the next advice still runs.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndTheNextAdviceStillRuns(): void {
		$objects = $this->createMock(ObjectService::class);
		$integriq = $this->createMock(IntegriqExchangeClient::class);
		$integriq->expects(self::exactly(2))->method('requestJob')->willReturnCallback(
			static function (string $target, string $direction, string $ownerRef): string {
				if ($ownerRef === 'school-advies/sa-1') {
					throw new RuntimeException('integriq unavailable');
				}

				return 'job-2';
			}
		);
		$objects->expects(self::once())->method('saveObject')->with(self::anything(), self::anything(), 'learniq', 'school-advies', 'sa-2');
		$logger = new CapturingLogger();

		$this->runDeferred(
			job: $this->job(['sa-1' => self::advies(), 'sa-2' => self::advies()], $objects, $integriq, $logger),
			entries: [['schoolAdviesId' => 'sa-1'], ['schoolAdviesId' => 'sa-2']]
		);

		self::assertCount(1, $logger->records);
		self::assertSame('warning', $logger->records[0]['level']);
		self::assertSame(['id' => 'sa-1', 'error' => 'integriq unavailable'], $logger->records[0]['context']);
	}//end testAFailureIsLoggedAndTheNextAdviceStillRuns()
}//end class
