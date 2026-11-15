<?php

/**
 * The persoonsgebonden nummer leaves learniq only in a ROD record and is never logged.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-personal-number-leaves-learniq-only-in-a-rod-message-and-is-never-logged
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DataExchangePayloadBuilder;
use OCA\Learniq\Service\DataExchangeTransformer;
use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Service\RodPersonalNumberResolver;
use OCA\Learniq\Service\RodSchoolAdviceComposer;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every other export path over a profile that holds a number.
 */
class RodPersonalNumberLeakTest extends TestCase {

	private const NUMBER = '111222333';

	/**
	 * Rows by register/schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * When set, every find throws with this message.
	 *
	 * @var string|null
	 */
	private ?string $findFails = null;

	/**
	 * Every log call.
	 *
	 * @var CapturingLogger
	 */
	private CapturingLogger $logger;

	/**
	 * The builder under test.
	 *
	 * @var DataExchangePayloadBuilder
	 */
	private DataExchangePayloadBuilder $builder;

	/**
	 * The resolver under test.
	 *
	 * @var RodPersonalNumberResolver
	 */
	private RodPersonalNumberResolver $resolver;

	/**
	 * Build over an in-memory store holding one profile with a number.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rows['learniq/learner-profile'] = [[
			'id' => 'lp-1',
			'ncUserId' => 'pupil-1',
			'tenant_id' => 't1',
			'eckId' => 'eck-1',
			'givenName' => 'Sanne',
			'familyName' => 'Bakker',
			'birthDate' => '2014-03-02',
			'schoolId' => '00AA',
			'personalNumber' => self::NUMBER,
			'personalNumberType' => 'bsn',
			'roles' => ['learner'],
		]];
		$this->rows['learniq/support-request'] = [['id' => 'sr-1', 'learnerId' => 'pupil-1', 'tenant_id' => 't1', 'supportDomain' => 'reading', 'description' => 'x', 'urgency' => 'normal']];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest): array {
				$filters = $config['filters'] ?? [];
				$key = ($filters['register'] ?? '') . '/' . ($filters['schema'] ?? '');
				$out = [];
				foreach (($this->rows[$key] ?? []) as $row) {
					$keep = true;
					foreach ($filters as $field => $value) {
						if (in_array($field, ['register', 'schema', 'tenant_id'], true) === false && ($row[$field] ?? null) !== $value) {
							$keep = false;
						}
					}

					if ($keep === true) {
						$out[] = OrEntityFactory::make($row, (string)($filters['schema'] ?? ''));
					}
				}

				return $out;
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, ...$rest): ?ObjectEntity {
				if ($this->findFails !== null) {
					throw new RuntimeException($this->findFails);
				}

				foreach (($this->rows[$register . '/' . $schema] ?? []) as $row) {
					if (($row['id'] ?? '') === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);

		$this->logger = new CapturingLogger();
		$this->resolver = new RodPersonalNumberResolver($objects, $this->logger);
		$this->builder = new DataExchangePayloadBuilder(
			$objects,
			new DataExchangeTransformer($objects),
			new ExchangeDisclosure(),
			$this->resolver,
			new RodSchoolAdviceComposer($objects, $this->resolver)
		);
	}//end setUp()

	/**
	 * No export other than ROD carries the number, the kind, or the ROD keys.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-an-hr-pass-through-export
	 * @spec openspec/specs/data-exchange/spec.md#scenario-an-oso-export
	 */
	public function testNoOtherExportCarriesTheNumber(): void {
		$paths = [
			['hr', null, 'learner-profile'],
			['bron-rod', null, 'learner-profile'],
			['oso', 'learniq-oso-export-dossier', 'learner-profile'],
			['uwlr', 'learniq-uwlr-export-pupil', 'learner-profile'],
			['edu-v', 'learniq-edu-v-export-onderwijsdeelnemers', 'learner-profile'],
			['basispoort', 'learniq-basispoort-sync-learner', 'learner-profile'],
			['entree', 'learniq-entree-content-sync-learner', 'learner-profile'],
			['swv', 'learniq-swv-export-zorgvraag', 'support-request'],
			// A ROD mapping on another target is not a ROD message.
			['uwlr', 'learniq-bron-rod-export-learner', 'learner-profile'],
		];

		foreach ($paths as [$target, $mapping, $schema]) {
			$records = $this->builder->composeRecords(target: $target, mappingSlug: $mapping, scope: ['schema' => $schema], tenantId: 't1');
			$this->assertNotSame([], $records, $target . ' composed nothing, so the check below would prove nothing.');
			$dump = (string)json_encode($records);
			$this->assertStringNotContainsString(self::NUMBER, $dump, $target . ' / ' . (string)$mapping . ' carried the number.');
			$this->assertStringNotContainsString('personalNumber', $dump, $target . ' / ' . (string)$mapping);
			$this->assertStringNotContainsString('persoonsgebondenNummer', $dump, $target . ' / ' . (string)$mapping);
		}
	}//end testNoOtherExportCarriesTheNumber()

	/**
	 * The ROD learner export does carry it: the control that shows the store holds a findable number.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-a-rod-export-sends-the-bsn-and-keeps-the-eck-id
	 */
	public function testTheRodExportCarriesIt(): void {
		$records = $this->builder->composeRecords(target: 'bron-rod', mappingSlug: 'learniq-bron-rod-export-learner', scope: ['schema' => 'learner-profile'], tenantId: 't1');

		$this->assertSame(self::NUMBER, $records[0]['data']['persoonsgebondenNummer']);
		$this->assertStringNotContainsString(self::NUMBER, $this->logger->dump());
	}//end testTheRodExportCarriesIt()

	/**
	 * Another tenant's job gets no number.
	 *
	 * @return void
	 */
	public function testAnotherTenantGetsNoNumber(): void {
		$this->assertNull($this->resolver->forProfile(profileId: 'lp-1', tenantId: 't2')['persoonsgebondenNummer']);
		$this->assertSame(self::NUMBER, $this->resolver->forProfile(profileId: 'lp-1', tenantId: 't1')['persoonsgebondenNummer']);
	}//end testAnotherTenantGetsNoNumber()

	/**
	 * A failed read logs neither the number nor the exception message that could quote it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-nothing-is-logged
	 */
	public function testAFailedReadLogsNoValue(): void {
		$this->findFails = 'row lp-1 personalNumber=' . self::NUMBER . ' failed';

		$pair = $this->resolver->forProfile(profileId: 'lp-1', tenantId: 't1');

		$this->assertNull($pair['persoonsgebondenNummer']);
		$this->assertNotSame([], $this->logger->records, 'The failure is logged, so the check below searches a real line.');
		$this->assertStringNotContainsString(self::NUMBER, $this->logger->dump());
		$this->assertStringContainsString('lp-1', $this->logger->dump());
	}//end testAFailedReadLogsNoValue()

	/**
	 * The elfproef for a BSN and the adapted one for an onderwijsnummer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
	 */
	public function testTheChecks(): void {
		$this->assertTrue(RodPersonalNumberResolver::isValid('111222333', 'bsn'));
		$this->assertTrue(RodPersonalNumberResolver::isValid('123456782', 'bsn'));
		$this->assertFalse(RodPersonalNumberResolver::isValid('123456789', 'bsn'));
		$this->assertFalse(RodPersonalNumberResolver::isValid('000000000', 'bsn'));
		$this->assertFalse(RodPersonalNumberResolver::isValid('11122233', 'bsn'));
		$this->assertFalse(RodPersonalNumberResolver::isValid('11122233a', 'bsn'));
		$this->assertTrue(RodPersonalNumberResolver::isValid('101234564', 'onderwijsnummer'));
		$this->assertFalse(RodPersonalNumberResolver::isValid('101234564', 'bsn'));
		$this->assertFalse(RodPersonalNumberResolver::isValid('111222333', 'onderwijsnummer'));
		$this->assertFalse(RodPersonalNumberResolver::isValid('111222333', 'passport'));
	}//end testTheChecks()
}//end class
