<?php

/**
 * Unit tests for the `oso-inbound-contract` register-JSON declarations.
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
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the OsoImportDossier schema declaration, its
 * received/under-review/accepted/rejected lifecycle, the admin/coordinator
 * RBAC read gate, the `oso` inbound job target, and the DataMappingProfile
 * import seed.
 */
class OsoImportDossierRegisterTest extends TestCase {

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
	 * Required fields cover the dossier's identity and job linkage.
	 *
	 * @return void
	 */
	public function testRequiredFields(): void {
		$schema = $this->config['components']['schemas']['OsoImportDossier'];

		self::assertSame(
			['dataExchangeJobId', 'sourceSchoolBrin', 'receivedAt', 'tenant_id'],
			$schema['required']
		);

		$props = $schema['properties'];
		self::assertSame('DataExchangeJob', $props['dataExchangeJobId']['$ref']);

	}//end testRequiredFields()

	/**
	 * draftProfile is a nullable snapshot object, not a $ref to LearnerProfile
	 * — it must never be a live write target.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-an-incoming-overstapdossier-lands-as-a-reviewable-draft-not-a-live-learnerprofile
	 */
	public function testDraftProfileIsSnapshotNotLiveWrite(): void {
		$prop = $this->config['components']['schemas']['OsoImportDossier']['properties']['draftProfile'];

		self::assertSame('object', $prop['type']);
		self::assertTrue($prop['nullable']);
		self::assertArrayNotHasKey('$ref', $prop, 'draftProfile must not $ref LearnerProfile — it is a snapshot, never a live write target');

	}//end testDraftProfileIsSnapshotNotLiveWrite()

	/**
	 * categories carries an illustrative, non-authoritative Besluit-style
	 * gegevensblok enum, each row flagging inclusion.
	 *
	 * @return void
	 */
	public function testCategoriesEnumShape(): void {
		$prop = $this->config['components']['schemas']['OsoImportDossier']['properties']['categories'];
		$itemProps = $prop['items']['properties'];

		self::assertSame(
			['basisgegevens', 'onderwijskundig-rapport', 'uitstroomgegevens', 'toetsgegevens', 'verzuimgegevens', 'zorggegevens'],
			$itemProps['category']['enum']
		);
		self::assertSame('boolean', $itemProps['included']['type']);

	}//end testCategoriesEnumShape()

	/**
	 * OsoImportDossier starts at `received` and only reaches `accepted`/
	 * `rejected` via guarded transitions from `under-review`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-a-received-dossier-is-not-accepted-until-a-coordinator-reviews-it
	 */
	public function testLifecycleTransitionShape(): void {
		$schema = $this->config['components']['schemas']['OsoImportDossier'];
		$lifecycle = $schema['x-openregister-lifecycle'];
		$transitions = $lifecycle['transitions'];

		self::assertSame('status', $lifecycle['property']);
		self::assertSame('received', $lifecycle['initial']);

		self::assertSame('received', $transitions['startReview']['from']);
		self::assertSame('under-review', $transitions['startReview']['to']);
		self::assertArrayNotHasKey('requires', $transitions['startReview']);

		self::assertSame('under-review', $transitions['accept']['from']);
		self::assertSame('accepted', $transitions['accept']['to']);
		self::assertSame('OCA\\Learniq\\Lifecycle\\OsoImportAcceptGuard', $transitions['accept']['requires']);

		self::assertSame('under-review', $transitions['reject']['from']);
		self::assertSame('rejected', $transitions['reject']['to']);
		self::assertSame('OCA\\Learniq\\Lifecycle\\OsoImportRejectGuard', $transitions['reject']['requires']);

	}//end testLifecycleTransitionShape()

	/**
	 * Read access is admin/coordinator only — no learner-self-read case,
	 * unlike LvsResult/AssessmentResult.
	 *
	 * @return void
	 */
	public function testRbacReadAdminCoordinatorOnly(): void {
		$anyOf = $this->config['components']['schemas']['OsoImportDossier']['x-property-rbac']['read']['anyOf'];

		self::assertCount(2, $anyOf);
		self::assertSame('admin', $anyOf[0]['role']);
		self::assertSame('coordinator', $anyOf[1]['role']);

	}//end testRbacReadAdminCoordinatorOnly()

	/**
	 * DataExchangeJob.target's description now documents the oso inbound
	 * direction.
	 *
	 * @return void
	 */
	public function testDataExchangeJobTargetDescribesOsoImport(): void {
		$prop = $this->config['components']['schemas']['DataExchangeJob']['properties']['target'];

		self::assertStringContainsString('OsoImportDossier', $prop['description']);

	}//end testDataExchangeJobTargetDescribesOsoImport()

	/**
	 * The oso (direction: import) DataMappingProfile seed maps the sending
	 * school's identity fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-the-oso-import-mapping-profile-declares-the-sending-schools-identity-fields
	 */
	public function testOsoImportMappingProfileSeedShape(): void {
		$seed = $this->config['components']['schemas']['DataMappingProfile']['x-openregister-seed'];

		$profile = null;
		foreach ($seed as $entry) {
			if ($entry['target'] === 'oso' && $entry['direction'] === 'import') {
				$profile = $entry;
				break;
			}
		}

		self::assertNotNull($profile, 'oso (direction: import) DataMappingProfile seed must exist');
		self::assertSame('oso-import-dossier', $profile['sourceSchema']);

		$mappedFields = array_column($profile['fieldMappings'], 'scholiqField');
		self::assertContains('learnerEckId', $mappedFields);
		self::assertContains('sourceSchoolBrin', $mappedFields);

	}//end testOsoImportMappingProfileSeedShape()

	/**
	 * The existing oso EXPORT seed (direction: export, PO→VO overstap) is
	 * untouched by this change.
	 *
	 * @return void
	 */
	public function testExistingOsoExportSeedUnchanged(): void {
		$seed = $this->config['components']['schemas']['DataMappingProfile']['x-openregister-seed'];

		$exportProfile = null;
		foreach ($seed as $entry) {
			if ($entry['target'] === 'oso' && $entry['direction'] === 'export') {
				$exportProfile = $entry;
				break;
			}
		}

		self::assertNotNull($exportProfile);
		self::assertSame('OSO transfer dossier', $exportProfile['name']);
		self::assertSame('learner-profile', $exportProfile['sourceSchema']);

	}//end testExistingOsoExportSeedUnchanged()

	/**
	 * Every OsoImportDossier property carries a title and description
	 * (gate-28 discipline).
	 *
	 * @return void
	 */
	public function testEveryPropertyHasTitleAndDescription(): void {
		$props = $this->config['components']['schemas']['OsoImportDossier']['properties'];
		foreach ($props as $name => $prop) {
			self::assertArrayHasKey('title', $prop, "OsoImportDossier.{$name} missing title");
			self::assertArrayHasKey('description', $prop, "OsoImportDossier.{$name} missing description");
		}

	}//end testEveryPropertyHasTitleAndDescription()
}//end class
