<?php

/**
 * Unit tests for the `lvs-import-contract` register-JSON declarations.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lvs-import-contract/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the LvsResult schema declaration, its lifecycle/RBAC, the
 * `lvs-results` DataExchangeJob target, and the DataMappingProfile seed.
 */
class LvsResultRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * LvsResult is append-only and required fields cover the four-provider
	 * import shape findings.md#6.5 asked for.
	 *
	 * @return void
	 */
	public function testRequiredFieldsAndAppendOnly(): void {
		$schema = $this->config['components']['schemas']['LvsResult'];

		self::assertTrue($schema['appendOnly']);
		self::assertSame(
			['provider', 'instrument', 'moment', 'learnerId', 'dataExchangeJobId', 'tenant_id'],
			$schema['required']
		);

		$props = $schema['properties'];
		self::assertSame(['cito', 'iep', 'boom', 'dia'], $props['provider']['enum']);

	}//end testRequiredFieldsAndAppendOnly()

	/**
	 * The normed-score fields (rawScore, vaardigheidsscore, niveau,
	 * referentieniveau, dle) are all nullable numbers/strings — a provider
	 * need not publish every one of them.
	 *
	 * @return void
	 */
	public function testNormedScoreFieldsAreNullable(): void {
		$props = $this->config['components']['schemas']['LvsResult']['properties'];

		foreach (['rawScore', 'vaardigheidsscore', 'niveau', 'referentieniveau', 'dle', 'takenAt'] as $field) {
			self::assertTrue($props[$field]['nullable'], "{$field} must be nullable");
			self::assertNull($props[$field]['default']);
		}

	}//end testNormedScoreFieldsAreNullable()

	/**
	 * assessmentResultId is a nullable $ref into AssessmentResult — set only
	 * when an in-app Assessment counterpart exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#scenario-an-imported-lvs-result-links-to-an-existing-assessmentresult-when-one-exists
	 */
	public function testAssessmentResultLinkIsNullable(): void {
		$props = $this->config['components']['schemas']['LvsResult']['properties'];

		self::assertSame('AssessmentResult', $props['assessmentResultId']['$ref']);
		self::assertTrue($props['assessmentResultId']['nullable']);
		self::assertNull($props['assessmentResultId']['default']);

		self::assertSame('DataExchangeJob', $props['dataExchangeJobId']['$ref']);

	}//end testAssessmentResultLinkIsNullable()

	/**
	 * LvsResult starts at `imported` and only reaches `verified` via a
	 * transition that requires LvsResultVerifyGuard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#scenario-an-imported-result-is-not-verified-until-a-coordinator-confirms-it
	 */
	public function testInitialLifecycleStateIsImported(): void {
		$schema = $this->config['components']['schemas']['LvsResult'];
		$lifecycle = $schema['x-openregister-lifecycle'];

		self::assertSame('lifecycle', $lifecycle['property']);
		self::assertSame('imported', $lifecycle['initial']);

		$verify = $lifecycle['transitions']['verify'];
		self::assertSame('imported', $verify['from']);
		self::assertSame('verified', $verify['to']);
		self::assertSame('OCA\\Learniq\\Lifecycle\\LvsResultVerifyGuard', $verify['requires']);

		$archive = $lifecycle['transitions']['archive'];
		self::assertSame(['imported', 'verified'], $archive['from']);
		self::assertSame('archived', $archive['to']);

	}//end testInitialLifecycleStateIsImported()

	/**
	 * Read access mirrors AssessmentResult: admin, or the learner reading
	 * their own result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#scenario-a-learner-can-read-their-own-lvs-results-but-not-another-learners
	 */
	public function testRbacReadMirrorsAssessmentResult(): void {
		$read = $this->config['components']['schemas']['LvsResult']['x-property-rbac']['read'];

		self::assertSame('admin', $read['anyOf'][0]['role']);
		self::assertSame('learnerId', $read['anyOf'][1]['match']['field']);
		self::assertSame('eq', $read['anyOf'][1]['match']['operator']);
		self::assertSame('$userId', $read['anyOf'][1]['match']['value']);

	}//end testRbacReadMirrorsAssessmentResult()

	/**
	 * DataExchangeJob.target's description now names the lvs-results target.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#scenario-delegate-the-lvs-results-import-to-openconnector
	 */
	public function testDataExchangeJobTargetDescribesLvsResults(): void {
		$prop = $this->config['components']['schemas']['DataExchangeJob']['properties']['target'];

		self::assertStringContainsString('lvs-results', $prop['description']);
		self::assertStringContainsString('UWLR', $prop['description']);

	}//end testDataExchangeJobTargetDescribesLvsResults()

	/**
	 * The lvs-results DataMappingProfile seed maps provider/instrument/moment
	 * plus every normed-score field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#scenario-the-lvs-results-mapping-profile-declares-the-normed-score-fields
	 */
	public function testLvsResultsMappingProfileSeedShape(): void {
		$seed = $this->config['components']['schemas']['DataMappingProfile']['x-openregister-seed'];

		$profile = null;
		foreach ($seed as $entry) {
			if ($entry['target'] === 'lvs-results') {
				$profile = $entry;
				break;
			}
		}

		self::assertNotNull($profile, 'lvs-results DataMappingProfile seed must exist');
		self::assertSame('import', $profile['direction']);
		self::assertSame('assessment-result', $profile['sourceSchema']);

		$mappedFields = array_column($profile['fieldMappings'], 'scholiqField');
		foreach (['provider', 'instrument', 'moment', 'rawScore', 'vaardigheidsscore', 'niveau', 'referentieniveau', 'dle'] as $field) {
			self::assertContains($field, $mappedFields, "{$field} must be mapped");
		}

	}//end testLvsResultsMappingProfileSeedShape()

	/**
	 * Every LvsResult property carries a title and description (gate-28
	 * discipline).
	 *
	 * @return void
	 */
	public function testEveryPropertyHasTitleAndDescription(): void {
		$props = $this->config['components']['schemas']['LvsResult']['properties'];
		foreach ($props as $name => $prop) {
			self::assertArrayHasKey('title', $prop, "LvsResult.{$name} missing title");
			self::assertArrayHasKey('description', $prop, "LvsResult.{$name} missing description");
		}

	}//end testEveryPropertyHasTitleAndDescription()
}//end class
