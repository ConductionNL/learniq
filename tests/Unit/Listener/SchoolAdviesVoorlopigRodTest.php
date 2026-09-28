<?php

/**
 * Unit tests for the voorlopig school advice to ROD (schooladvies-voorlopig-to-rod).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\TransitionBridgeListenerRegistrar;
use OCA\Learniq\BackgroundJob\SchoolAdviesVoorlopigRodJob;
use OCA\Learniq\Listener\SchoolAdviesVoorlopigRodHandler;
use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\SchoolAdviesRodTiming;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The voorlopig advice is queued once given, and the job sends it once.
 */
class SchoolAdviesVoorlopigRodTest extends TestCase {

	/**
	 * Entries the handler queued.
	 *
	 * @var array<int, array{jobClass: string, entry: array<string, mixed>, dedupeKey: string|null}>
	 */
	public array $queued = [];

	/**
	 * A voorlopig advice with its level and date given.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function advies(array $override = []): array {
		return array_merge(
			[
				'id' => 'sa-1',
				'learnerId' => 'pupil1',
				'academicYear' => '2025-2026',
				'voorlopigAdviesLevel' => 'havo',
				'voorlopigAdviesDate' => '2026-01-20',
				'tenant_id' => 't1',
				'lifecycle' => 'voorlopig',
			],
			$override
		);
	}//end advies()

	/**
	 * The handler over a recording deferral.
	 *
	 * @param string $schema The schema the saved object resolves to.
	 *
	 * @return SchoolAdviesVoorlopigRodHandler
	 */
	private function handler(string $schema = 'school-advies'): SchoolAdviesVoorlopigRodHandler {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('registerSlug')->willReturn('learniq');
		$resolver->method('schemaSlug')->willReturn($schema);
		$test = $this;
		$deferral = new class ($test) extends ListenerDeferralService {
			/**
			 * Constructor.
			 *
			 * @param SchoolAdviesVoorlopigRodTest $test Records the entries.
			 */
			public function __construct(private readonly SchoolAdviesVoorlopigRodTest $test) {
			}//end __construct()

			/**
			 * Record the entry.
			 *
			 * @param string $jobClass The job class.
			 * @param array<string, mixed> $entry The entry.
			 * @param int $chunkSize Unused.
			 * @param string|null $dedupeKey The dedupe key.
			 *
			 * @return void
			 */
			public function defer(string $jobClass, array $entry, int $chunkSize = self::DEFAULT_CHUNK_SIZE, ?string $dedupeKey = null): void {
				$this->test->queued[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}//end defer()
		};

		return new SchoolAdviesVoorlopigRodHandler($deferral, $resolver, new SchoolAdviesRodTiming());
	}//end handler()

	/**
	 * A voorlopig advice that is given is queued once per advice; one without
	 * a level or date, one already sent, a definitief one, or another schema is not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schooladvies-voorlopig-to-rod/specs/data-exchange/spec.md#scenario-a-voorlopig-advice-goes-to-rod-when-it-is-given
	 */
	public function testTheHandlerQueuesAGivenVoorlopigAdvice(): void {
		$this->handler()->handle(new ObjectCreatedEvent(OrEntityFactory::make(self::advies(), 'school-advies')));
		self::assertCount(1, $this->queued);
		self::assertSame(SchoolAdviesVoorlopigRodJob::class, $this->queued[0]['jobClass']);
		self::assertSame(['schoolAdviesId' => 'sa-1'], $this->queued[0]['entry']);
		self::assertSame('voorlopig|sa-1', $this->queued[0]['dedupeKey']);

		$this->queued = [];
		foreach ([
			self::advies(['voorlopigAdviesDate' => null]),
			self::advies(['voorlopigAdviesLevel' => '']),
			self::advies(['voorlopigExchangeJobId' => 'job-9']),
			self::advies(['lifecycle' => 'definitief']),
		] as $advies) {
			$entity = OrEntityFactory::make($advies, 'school-advies');
			$this->handler()->handle(new ObjectUpdatedEvent($entity, $entity));
		}

		$this->handler(schema: 'grade-entry')->handle(new ObjectCreatedEvent(OrEntityFactory::make(self::advies(), 'grade-entry')));
		self::assertSame([], $this->queued);
	}//end testTheHandlerQueuesAGivenVoorlopigAdvice()

	/**
	 * The job asks integriq for the bron-rod schooladvies exchange with the
	 * school advice mapping and stamps the job id; an advice sent meanwhile is
	 * not sent again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schooladvies-voorlopig-to-rod/specs/data-exchange/spec.md#scenario-a-voorlopig-advice-goes-to-rod-when-it-is-given
	 */
	public function testTheJobSendsOnceAndStampsTheJob(): void {
		$stored = self::advies();
		$saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function () use (&$stored): ObjectEntity {
				return OrEntityFactory::make($stored, 'school-advies');
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null, $uuid = null) use (&$saved, &$stored): ObjectEntity {
				$saved[] = ['schema' => $schema, 'object' => $object, 'uuid' => $uuid];
				$stored = $object;
				return OrEntityFactory::make($object, (string)$schema);
			}
		);
		$integriq = $this->createMock(IntegriqExchangeClient::class);
		$integriq->expects($this->once())->method('requestJob')->with(
			'bron-rod',
			'export',
			'school-advies/sa-1',
			['schema' => 'school-advies', 'recordIds' => ['sa-1'], 'tenantId' => 't1', 'berichtsoort' => 'schooladvies'],
			ExchangeDisclosure::ROD_SCHOOL_ADVICE_MAPPING,
			'system',
			'ROD voorlopig schooladvies'
		)->willReturn('job-7');

		$job = new SchoolAdviesVoorlopigRodJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			new NullLogger(),
			$objects,
			$integriq,
			new SchoolAdviesRodTiming()
		);
		$run = new ReflectionMethod($job, 'runDeferred');
		$run->setAccessible(true);
		$context = new DeferredListenerContext(userId: 'teacher-1', orgUuid: null, entries: [['schoolAdviesId' => 'sa-1']]);

		$run->invoke($job, $context);
		$run->invoke($job, $context);

		self::assertCount(1, $saved);
		self::assertSame('job-7', $saved[0]['object']['voorlopigExchangeJobId']);
		self::assertSame('sa-1', $saved[0]['uuid']);
	}//end testTheJobSendsOnceAndStampsTheJob()

	/**
	 * The handler is registered on create and update.
	 *
	 * @return void
	 */
	public function testTheHandlerIsRegistered(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new TransitionBridgeListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatedEvent::class . ' => ' . SchoolAdviesVoorlopigRodHandler::class, $pairs);
		self::assertContains(ObjectUpdatedEvent::class . ' => ' . SchoolAdviesVoorlopigRodHandler::class, $pairs);
	}//end testTheHandlerIsRegistered()

	/**
	 * The register declares where the voorlopig job is stamped.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresTheVoorlopigJobField(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$field = $register['components']['schemas']['SchoolAdvies']['properties']['voorlopigExchangeJobId'] ?? null;

		self::assertIsArray($field);
		self::assertTrue($field['nullable']);
	}//end testTheRegisterDeclaresTheVoorlopigJobField()
}//end class
