<?php

/**
 * Unit tests for ExchangeImportLandingListener and ExchangeImportLanding
 * (import-landing-answer), against a verbatim copy of integriq's
 * ExchangeRecordsReceivedEvent (integriq exchange-import-landing).
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

use DateTime;
use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use OCA\Learniq\AppInfo\Registrar\CaseListenerRegistrar;
use OCA\Learniq\Listener\ExchangeImportLandingListener;
use OCA\Learniq\Service\ExchangeImportLanding;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lands LVS results, OSO dossiers and migrated pupils, and always answers.
 */
class ExchangeImportLandingListenerTest extends TestCase {

	/**
	 * In-memory rows by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $db = [];

	/**
	 * Writes made, as [schema, row, rbac].
	 *
	 * @var array<int, array{schema: string, row: array<string, mixed>, rbac: bool}>
	 */
	private array $writes = [];

	/**
	 * Build the listener over an in-memory store.
	 *
	 * @return ExchangeImportLandingListener
	 */
	private function makeListener(): ExchangeImportLandingListener {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['schema'], $filters['register']);
				return array_values(
					array_filter(
						$this->db[$schema] ?? [],
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true) {
				$this->writes[] = ['schema' => (string)$schema, 'row' => $object, 'rbac' => $_rbac];
				$object['id'] = $object['id'] ?? ($schema . '-' . count($this->writes));
				$this->db[(string)$schema] = array_values(array_filter($this->db[(string)$schema] ?? [], static fn (array $r): bool => ($r['id'] ?? null) !== $object['id']));
				$this->db[(string)$schema][] = $object;
				return OrEntityFactory::make($object, (string)$schema);
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-28T10:00:00+00:00'));

		return new ExchangeImportLandingListener(landing: new ExchangeImportLanding(objectService: $objects, time: $time), logger: new NullLogger());
	}//end makeListener()

	/**
	 * Dispatch a received-records event through the listener.
	 *
	 * @param string $target The target.
	 * @param array<int, array<string, mixed>> $records The records.
	 * @param string $owner The owning app.
	 *
	 * @return ExchangeRecordsReceivedEvent
	 */
	private function receive(string $target, array $records, string $owner = 'learniq'): ExchangeRecordsReceivedEvent {
		$event = new ExchangeRecordsReceivedEvent('job-1', $owner, $target, 'import', '', ['tenantId' => 't1'], $records);
		$this->makeListener()->handle($event);
		return $event;
	}//end receive()

	/**
	 * An LVS result lands as imported; an unknown pupil and a missing field
	 * are rejected by code and field name only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-an-lvs-result-lands-as-imported
	 */
	public function testLvsResultsLandAsImported(): void {
		$this->db['learner-profile'] = [['id' => 'p1', 'ncUserId' => 'pupil1']];
		$event = $this->receive(
			'lvs-results',
			[
				['recordId' => 'r1', 'sourceKind' => 'lvs-result', 'data' => ['provider' => 'cito', 'instrument' => 'Rekenen', 'moment' => 'M6', 'vaardigheidsscore' => 187, 'learnerId' => 'pupil1']],
				['recordId' => 'r2', 'sourceKind' => 'lvs-result', 'data' => ['provider' => 'cito', 'instrument' => 'Rekenen', 'moment' => 'M6', 'learnerId' => 'stranger']],
				['recordId' => 'r3', 'sourceKind' => 'lvs-result', 'data' => ['provider' => 'cito', 'learnerId' => 'pupil1']],
			]
		);

		self::assertTrue($event->isAnswered());
		self::assertSame(1, $event->getAcceptedCount());
		$rejected = array_column($event->getRejected(), null, 'recordId');
		self::assertSame('LVS-UNKNOWN-PUPIL', $rejected['r2']['errorCode']);
		self::assertSame('LVS-MISSING-FIELD', $rejected['r3']['errorCode']);
		self::assertSame(['instrument', 'moment'], $rejected['r3']['offendingFields']);

		self::assertCount(1, $this->writes);
		self::assertSame('lvs-result', $this->writes[0]['schema']);
		self::assertSame('imported', $this->writes[0]['row']['lifecycle']);
		self::assertSame('job-1', $this->writes[0]['row']['dataExchangeJobId']);
		self::assertSame('t1', $this->writes[0]['row']['tenant_id']);
		self::assertFalse($this->writes[0]['rbac'], 'a background job has no session: the write is system-level');
	}//end testLvsResultsLandAsImported()

	/**
	 * A second delivery changes nothing: an imported row is updated in place,
	 * a verified one is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-a-second-delivery-changes-nothing-already-taken
	 */
	public function testASecondDeliveryIsIdempotent(): void {
		$this->db['learner-profile'] = [['id' => 'p1', 'ncUserId' => 'pupil1']];
		$this->db['lvs-result'] = [['id' => 'lvs-a', 'provider' => 'cito', 'instrument' => 'Rekenen', 'moment' => 'M6', 'learnerId' => 'pupil1', 'vaardigheidsscore' => 187, 'lifecycle' => 'verified']];
		$record = ['recordId' => 'r1', 'sourceKind' => 'lvs-result', 'data' => ['provider' => 'cito', 'instrument' => 'Rekenen', 'moment' => 'M6', 'vaardigheidsscore' => 999, 'learnerId' => 'pupil1']];

		$event = $this->receive('lvs-results', [$record]);
		self::assertSame(1, $event->getAcceptedCount());
		self::assertSame([], $this->writes, 'a verified result is frozen');

		$this->db['lvs-result'][0]['lifecycle'] = 'imported';
		$this->receive('lvs-results', [$record]);
		self::assertCount(1, $this->writes);
		self::assertSame('lvs-a', $this->writes[0]['row']['id'], 'an imported row is updated in place, not duplicated');
	}//end testASecondDeliveryIsIdempotent()

	/**
	 * An OSO dossier lands as received, held for review, once.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-an-oso-dossier-is-held-for-review
	 */
	public function testAnOsoDossierIsHeldForReview(): void {
		$record = ['recordId' => 'd1', 'sourceKind' => 'oso-dossier', 'data' => ['sourceSchoolBrin' => '12AB', 'learnerEckId' => 'eck-1', 'categories' => [['code' => 'basis']]]];

		$event = $this->receive('oso', [$record, ['recordId' => 'd2', 'sourceKind' => 'oso-dossier', 'data' => ['learnerEckId' => 'eck-2']]]);

		self::assertSame(1, $event->getAcceptedCount());
		self::assertSame('OSO-MISSING-FIELD', $event->getRejected()[0]['errorCode']);
		self::assertSame('received', $this->writes[0]['row']['status']);
		self::assertSame('2026-09-28T10:00:00+00:00', $this->writes[0]['row']['receivedAt']);

		$this->receive('oso', [$record]);
		self::assertCount(1, $this->writes, 'the same dossier delivered again is not stored twice');
	}//end testAnOsoDossierIsHeldForReview()

	/**
	 * A migrated pupil gets a profile, or fills only the gaps of the one they have.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-a-migrated-pupil-fills-only-the-gaps-of-their-profile
	 */
	public function testMigrationFillsOnlyTheGaps(): void {
		$this->db['learner-profile'] = [['id' => 'p1', 'ncUserId' => 'pupil1', 'givenName' => 'Anna', 'familyName' => '']];

		$event = $this->receive(
			'migration-import',
			[
				['recordId' => 'm1', 'sourceKind' => 'learner', 'data' => ['ncUserId' => 'pupil1', 'givenName' => 'Other', 'familyName' => 'Jansen', 'roles' => ['admin'], 'bsnEncrypted' => 'x']],
				['recordId' => 'm2', 'sourceKind' => 'learner', 'data' => ['ncUserId' => 'pupil2', 'givenName' => 'Bo']],
				['recordId' => 'm3', 'sourceKind' => 'learner', 'data' => ['givenName' => 'Nobody']],
			]
		);

		self::assertSame(2, $event->getAcceptedCount());
		self::assertSame('MIGRATION-MISSING-FIELD', $event->getRejected()[0]['errorCode']);
		$updated = $this->writes[0]['row'];
		self::assertSame('Anna', $updated['givenName'], 'what the school holds is never overwritten');
		self::assertSame('Jansen', $updated['familyName']);
		self::assertArrayNotHasKey('roles', $updated);
		self::assertArrayNotHasKey('bsnEncrypted', $updated);
		self::assertSame(['givenName' => 'Bo', 'ncUserId' => 'pupil2', 'tenant_id' => 't1', 'lifecycle' => 'active'], $this->writes[1]['row']);
	}//end testMigrationFillsOnlyTheGaps()

	/**
	 * Another app's job, another target, or a failing write: the listener
	 * answers only its own, and always answers those.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function testAnswersOnlyItsOwnJobs(): void {
		self::assertFalse($this->receive('lvs-results', [], 'shillinq')->isAnswered());
		self::assertFalse($this->receive('uwlr', [])->isAnswered());

		$empty = $this->receive('migration-import', []);
		self::assertTrue($empty->isAnswered());
		self::assertSame(0, $empty->getAcceptedCount());
	}//end testAnswersOnlyItsOwnJobs()

	/**
	 * The listener is registered for integriq's event.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegistered(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new CaseListenerRegistrar())->register(context: $context);

		self::assertContains(ExchangeRecordsReceivedEvent::class . ' => ' . ExchangeImportLandingListener::class, $pairs);
	}//end testTheListenerIsRegistered()
}//end class
