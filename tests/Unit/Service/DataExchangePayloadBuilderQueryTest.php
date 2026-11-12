<?php

/**
 * Tests for the reads DataExchangePayloadBuilder::composeRecords() makes:
 * every query it sends to OpenRegister, tenant-forced and bounded, and what
 * each answer becomes in the records.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DataExchangePayloadBuilder;
use OCA\Learniq\Service\DataExchangeTransformer;
use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Service\RodPersonalNumberResolver;
use OCA\Learniq\Service\RodSchoolAdviceComposer;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The builder's queries, recorded.
 */
class DataExchangePayloadBuilderQueryTest extends TestCase {

	/**
	 * Every query the builder sent, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The answer per schema and id (`*` answers any query on the schema).
	 *
	 * @var array<string, array<string, array<int, mixed>>>
	 */
	private array $answers = [];

	/**
	 * The builder over the recorded store.
	 *
	 * @return DataExchangePayloadBuilder The builder.
	 */
	private function builder(): DataExchangePayloadBuilder {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->queries[] = $config;
				$schema = (string)($config['filters']['schema'] ?? '');
				$key = (string)($config['ids'][0] ?? '*');
				return ($this->answers[$schema][$key] ?? []);
			}
		);

		$resolver = new RodPersonalNumberResolver($objects, new NullLogger());
		return new DataExchangePayloadBuilder($objects, new DataExchangeTransformer($objects), new ExchangeDisclosure(), $resolver, new RodSchoolAdviceComposer($objects, $resolver));
	}//end builder()

	/**
	 * A scope's filters, cohort and tenant reach the read; recordIds narrow
	 * the answer by id or uuid, in store order.
	 *
	 * @return void
	 */
	public function testTheScopeReadIsTenantForcedAndNarrowed(): void {
		$this->answers['learner-profile']['*'] = [
			['id' => 'lp-1', 'givenName' => 'A'],
			OrEntityFactory::make(['givenName' => 'B'], 'learner-profile', 'learniq', 'uuid-2'),
			['uuid' => 'lp-3', 'givenName' => 'C'],
		];

		$records = $this->builder()->composeRecords(
			'hr',
			null,
			['schema' => 'learner-profile', 'filters' => ['status' => 'active'], 'cohortId' => 'c-1', 'recordIds' => ['lp-3', 'lp-1', 9]],
			'tenant-a'
		);

		self::assertSame(
			[
				[
					'filters' => ['status' => 'active', 'cohortId' => 'c-1', 'tenant_id' => 'tenant-a', 'register' => 'learniq', 'schema' => 'learner-profile'],
					'limit'   => DataExchangePayloadBuilder::QUERY_LIMIT,
				],
			],
			$this->queries
		);
		self::assertSame(['lp-1', 'lp-3'], array_column($records, 'recordId'));
		self::assertSame(['id' => 'lp-1', 'givenName' => 'A'], $records[0]['data']);
	}//end testTheScopeReadIsTenantForcedAndNarrowed()

	/**
	 * Odd scopes: no schema reads nothing; non-array filters, an empty cohort,
	 * no tenant and an empty recordIds list leave the read unnarrowed.
	 *
	 * @return void
	 */
	public function testOddScopesReadPlainly(): void {
		self::assertSame([], $this->builder()->composeRecords('hr', null, ['filters' => ['x' => 1]], 'tenant-a'));
		self::assertSame([], $this->queries);

		$this->answers['cohort']['*'] = [['id' => 'c-1'], ['id' => 'c-2']];
		$records = $this->builder()->composeRecords('hr', null, ['schema' => 'cohort', 'filters' => 'nope', 'cohortId' => '', 'recordIds' => []], '');
		$this->builder()->composeRecords('hr', null, ['schema' => 'cohort', 'cohortId' => 7, 'recordIds' => 'c-1'], '');

		self::assertSame(
			[
				['filters' => ['register' => 'learniq', 'schema' => 'cohort'], 'limit' => DataExchangePayloadBuilder::QUERY_LIMIT],
				['filters' => ['register' => 'learniq', 'schema' => 'cohort'], 'limit' => DataExchangePayloadBuilder::QUERY_LIMIT],
			],
			$this->queries
		);
		self::assertSame(['c-1', 'c-2'], array_column($records, 'recordId'));
	}//end testOddScopesReadPlainly()

	/**
	 * A scope that reaches the limit is refused rather than cut off.
	 *
	 * @return void
	 */
	public function testAScopeAtTheLimitIsRefused(): void {
		$this->answers['cohort']['*'] = array_fill(0, DataExchangePayloadBuilder::QUERY_LIMIT, ['id' => 'c']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The exchange scope selects 5000 or more 'cohort' objects; narrow it so nothing is cut off.");
		$this->builder()->composeRecords('hr', null, ['schema' => 'cohort'], '');
	}//end testAScopeAtTheLimitIsRefused()

	/**
	 * A leerplicht file reads each breaching record by id in the flag's
	 * tenant, skips unusable ids and missing records, and strips personal data.
	 *
	 * @return void
	 */
	public function testALeerplichtFileResolvesItsBreachingRecords(): void {
		$this->answers['absence-flag']['*'] = [
			['id' => 'f-1', 'tenant_id' => 't-1', 'breachingRecordIds' => ['ar-1', '', 5, 'ar-missing', 'ar-2'], 'interventions' => 'none'],
			['id' => 'f-2', 'breachingRecordIds' => 'ar-1', 'interventions' => [['kind' => 'call']]],
		];
		$this->answers['attendance-record']['ar-1'] = [['id' => 'ar-1', 'status' => 'absent', 'bsnEncrypted' => 'S', 'bsnHash' => 'H', 'email' => 'e']];
		$this->answers['attendance-record']['ar-2'] = [OrEntityFactory::make(['status' => 'late', 'email' => 'e'], 'attendance-record', 'learniq', 'ar-2')];

		$records = $this->builder()->composeRecords('leerplicht', null, ['schema' => 'absence-flag'], '');

		self::assertSame(
			[
				['filters' => ['register' => 'learniq', 'schema' => 'absence-flag'], 'limit' => DataExchangePayloadBuilder::QUERY_LIMIT],
				['ids' => ['ar-1'], 'filters' => ['tenant_id' => 't-1', 'register' => 'learniq', 'schema' => 'attendance-record'], 'limit' => 1],
				['ids' => ['ar-missing'], 'filters' => ['tenant_id' => 't-1', 'register' => 'learniq', 'schema' => 'attendance-record'], 'limit' => 1],
				['ids' => ['ar-2'], 'filters' => ['tenant_id' => 't-1', 'register' => 'learniq', 'schema' => 'attendance-record'], 'limit' => 1],
			],
			$this->queries
		);
		$first = $records[0]['data'];
		self::assertSame(['id' => 'ar-1', 'status' => 'absent'], $first['breachingRecords'][0]);
		self::assertSame('late', $first['breachingRecords'][1]['status']);
		self::assertArrayNotHasKey('email', $first['breachingRecords'][1]);
		self::assertCount(2, $first['breachingRecords']);
		self::assertSame([], $first['interventions']);
		self::assertSame([], $records[1]['data']['breachingRecords']);
		self::assertSame([['kind' => 'call']], $records[1]['data']['interventions']);
	}//end testALeerplichtFileResolvesItsBreachingRecords()

	/**
	 * An SWV file reads the learner by user id and the plan by id, both in
	 * the request's tenant, and keeps only their whitelisted fields.
	 *
	 * @return void
	 */
	public function testAnSwvFileReadsTheLearnerAndPlanWhitelists(): void {
		$this->answers['support-request']['*'] = [
			['id' => 'sr-1', 'tenant_id' => 't-1', 'learnerId' => 'pupil-1', 'learningPlanId' => 'plan-1'],
			['id' => 'sr-2', 'learnerId' => '', 'learningPlanId' => 'plan-missing'],
			['id' => 'sr-3', 'learnerId' => 'pupil-missing', 'learningPlanId' => 4],
		];
		$this->answers['learner-profile']['*'] = [];
		$this->answers['learning-plan']['plan-1'] = [OrEntityFactory::make(['kind' => 'opp', 'goals' => ['g'], 'cohortId' => 'c'], 'learning-plan', 'learniq', 'plan-1')];

		$profileStore = $this->answers;
		$this->answers['learner-profile']['*'] = [['eckId' => 'eck-1', 'givenName' => 'S', 'familyName' => 'B', 'email' => 'e', 'bsnHash' => 'H', 'schoolId' => 's']];
		$records = $this->builder()->composeRecords('swv', null, ['schema' => 'support-request', 'recordIds' => ['sr-1', 'sr-2']], '');
		$this->answers = $profileStore;
		$missing = $this->builder()->composeRecords('swv', null, ['schema' => 'support-request', 'recordIds' => ['sr-3']], '');

		self::assertSame(
			[
				['filters' => ['register' => 'learniq', 'schema' => 'support-request'], 'limit' => DataExchangePayloadBuilder::QUERY_LIMIT],
				['filters' => ['ncUserId' => 'pupil-1', 'tenant_id' => 't-1', 'register' => 'learniq', 'schema' => 'learner-profile'], 'limit' => 1],
				['ids' => ['plan-1'], 'filters' => ['tenant_id' => 't-1', 'register' => 'learniq', 'schema' => 'learning-plan'], 'limit' => 1],
				['ids' => ['plan-missing'], 'filters' => ['register' => 'learniq', 'schema' => 'learning-plan'], 'limit' => 1],
				['filters' => ['register' => 'learniq', 'schema' => 'support-request'], 'limit' => DataExchangePayloadBuilder::QUERY_LIMIT],
				['filters' => ['ncUserId' => 'pupil-missing', 'register' => 'learniq', 'schema' => 'learner-profile'], 'limit' => 1],
			],
			$this->queries
		);
		self::assertSame(
			['eckId' => 'eck-1', 'givenName' => 'S', 'familyName' => 'B', 'birthDate' => null, 'schoolId' => 's'],
			$records[0]['data']['learner']
		);
		self::assertSame(['kind' => 'opp', 'period' => null, 'goals' => ['g'], 'supportMeasures' => []], $records[0]['data']['learningPlanContext']);
		self::assertNull($records[1]['data']['learner']);
		self::assertNull($records[1]['data']['learningPlanContext']);
		self::assertNull($missing[0]['data']['learner']);
		self::assertNull($missing[0]['data']['learningPlanContext']);
	}//end testAnSwvFileReadsTheLearnerAndPlanWhitelists()

	/**
	 * A mapping's field list keeps only those fields; the BRIN comes from the
	 * cohort transform, a missing field is null.
	 *
	 * @return void
	 */
	public function testAFieldListKeepsOnlyItsFields(): void {
		$this->answers['learner-profile']['*'] = [['id' => 'lp-1', 'eckId' => 'eck-1', 'givenName' => 'S', 'email' => 'e', 'cohortId' => 'c-1']];
		$this->answers['cohort']['*'] = [['id' => 'c-1', 'brinNumber' => '12AB']];
		$this->answers['cohort']['c-1'] = $this->answers['cohort']['*'];

		$records = $this->builder()->composeRecords('oso', 'learniq-oso-export-dossier', ['schema' => 'learner-profile'], '');

		self::assertSame(
			['eckId' => 'eck-1', 'givenName' => 'S', 'familyName' => null, 'birthDate' => null, 'schoolBrin' => '12AB'],
			$records[0]['data']
		);
	}//end testAFieldListKeepsOnlyItsFields()
}//end class
