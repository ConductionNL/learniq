<?php

/**
 * Unit tests for the parent or guardian role on a praktijkovereenkomst.
 *
 * A minor cannot sign a work placement agreement alone. The register must
 * let a parent sign (`PokSignature.signerRole: parent`), record whether one
 * must (`Praktijkovereenkomst.parentSignatureRequired`, stamped by a
 * transition action), and keep `isFullySigned` honest about it.
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
 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-three-party-pok-signing-reuses-the-signature-pattern-via-poksignature
 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the parent role, the stamped flag and the calculation that reads it.
 */
class PokParentRoleRegisterTest extends TestCase {

	private const STAMP_ACTION = 'OCA\\Learniq\\Lifecycle\\Action\\PokParentSignatureStampAction';

	/**
	 * Decoded register schemas.
	 *
	 * @var array<string, mixed>
	 */
	private array $schemas;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config        = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$this->schemas = $config['components']['schemas'];
	}//end setUp()

	/**
	 * A parent can sign, and the three original roles keep their place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#scenario-a-parent-signs-a-minors-agreement
	 */
	public function testAParentCanSign(): void {
		$roles = $this->schemas['PokSignature']['properties']['signerRole']['enum'];

		self::assertSame(['student', 'school', 'praktijkopleider'], array_slice($roles, 0, 3));
		self::assertContains('parent', $roles);
		self::assertTrue(version_compare($this->schemas['PokSignature']['version'], '0.2.0', '>='));
	}//end testAParentCanSign()

	/**
	 * The agreement records whether a parent signs, and the server stamps it
	 * when signatures are requested and on activation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
	 */
	public function testTheAgreementRecordsWhetherAParentSigns(): void {
		$pok = $this->schemas['Praktijkovereenkomst'];

		self::assertSame('boolean', $pok['properties']['parentSignatureRequired']['type']);
		self::assertFalse($pok['properties']['parentSignatureRequired']['default']);
		self::assertTrue(version_compare($pok['version'], '0.2.0', '>='));

		foreach (['requestSignatures', 'activate'] as $transition) {
			$actions = array_column($pok['x-openregister-lifecycle']['transitions'][$transition]['actions'] ?? [], 'action');
			self::assertContains(self::STAMP_ACTION, $actions, $transition . ' stamps parentSignatureRequired');
		}

		self::assertSame(
			'OCA\\Learniq\\Lifecycle\\PokActivationGuard',
			$pok['x-openregister-lifecycle']['transitions']['activate']['requires']
		);
	}//end testTheAgreementRecordsWhetherAParentSigns()

	/**
	 * `isFullySigned` asks for the parent exactly when the flag says so, and
	 * counts parent signatures on the current version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testFullySignedIncludesTheParentWhenOneIsRequired(): void {
		$pok = $this->schemas['Praktijkovereenkomst'];

		$count = $pok['x-openregister-aggregate-refs']['parentSignatureCount'];
		self::assertSame('pok-signature', $count['schema']);
		self::assertSame(['subjectId' => '@self.id', 'subjectVersion' => '@self.version', 'signerRole' => 'parent'], $count['filters']);

		$clauses = $pok['x-openregister-calculations']['isFullySigned']['expression']['and'];
		self::assertContains(
			[
				'or' => [
					['ne' => [['prop' => 'parentSignatureRequired'], true]],
					['gte' => [['prop' => '@aggregate.parentSignatureCount'], 1]],
				],
			],
			$clauses
		);
		self::assertGreaterThanOrEqual(4, count($clauses), 'the three original roles stay required');
	}//end testFullySignedIncludesTheParentWhenOneIsRequired()
}//end class
