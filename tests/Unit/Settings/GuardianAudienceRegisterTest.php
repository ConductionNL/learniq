<?php

/**
 * Unit tests for the `portal-contribution-guardian-audiences` register delta.
 *
 * Asserts the two additive LearnerProfile properties this change introduces:
 * beeldmateriaalConsent (per-purpose, nullable-boolean) and
 * beeldmateriaalConsentReviewDueAt. PortalContributionProvider's own
 * manifest-shape coverage lives in PortalContributionProviderTest.
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
 * @spec openspec/changes/portal-contribution-guardian-audiences/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the portal-contribution-guardian-audiences register delta.
 */
class GuardianAudienceRegisterTest extends TestCase {

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
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$raw = file_get_contents($path);
		$this->assertNotFalse($raw, 'learniq_register.json must be readable');

		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded, 'learniq_register.json must be valid JSON');
		$this->config = $decoded;

	}//end setUp()

	/**
	 * LearnerProfile.beeldmateriaalConsent carries the five documented
	 * purposes, each a nullable boolean defaulting to null, and is not
	 * required.
	 *
	 * @return void
	 * @spec   openspec/changes/portal-contribution-guardian-audiences/specs/avg-verwerkingsregister/spec.md#requirement-learnerprofile-records-per-purpose-beeldmateriaal-consent
	 */
	public function testLearnerProfileGainsBeeldmateriaalConsent(): void {
		$schema = $this->config['components']['schemas']['LearnerProfile'] ?? null;
		$this->assertIsArray($schema, 'LearnerProfile schema MUST exist');

		$consent = $schema['properties']['beeldmateriaalConsent'] ?? null;
		$this->assertIsArray($consent, 'LearnerProfile MUST declare beeldmateriaalConsent');
		$this->assertSame('object', $consent['type'] ?? null);

		$purposes = ['website', 'socialMedia', 'schoolgids', 'classPhoto', 'video'];
		$this->assertEqualsCanonicalizing($purposes, array_keys($consent['properties'] ?? []));

		foreach ($purposes as $purpose) {
			$field = $consent['properties'][$purpose];
			$this->assertSame('boolean', $field['type'] ?? null, "$purpose MUST be boolean");
			$this->assertTrue($field['nullable'] ?? false, "$purpose MUST be nullable");
			$this->assertArrayHasKey('default', $field);
			$this->assertNull($field['default'], "$purpose MUST default to null");
			$this->assertArrayHasKey('title', $field);
			$this->assertArrayHasKey('description', $field);
		}

		$this->assertNotContains('beeldmateriaalConsent', $schema['required'] ?? []);

	}//end testLearnerProfileGainsBeeldmateriaalConsent()

	/**
	 * LearnerProfile.beeldmateriaalConsentReviewDueAt is a nullable date,
	 * default null, not required.
	 *
	 * @return void
	 * @spec   openspec/changes/portal-contribution-guardian-audiences/specs/avg-verwerkingsregister/spec.md#requirement-learnerprofile-records-per-purpose-beeldmateriaal-consent
	 */
	public function testLearnerProfileGainsConsentReviewDueDate(): void {
		$schema = $this->config['components']['schemas']['LearnerProfile'] ?? null;
		$property = $schema['properties']['beeldmateriaalConsentReviewDueAt'] ?? null;

		$this->assertIsArray($property, 'LearnerProfile MUST declare beeldmateriaalConsentReviewDueAt');
		$this->assertSame('string', $property['type'] ?? null);
		$this->assertSame('date', $property['format'] ?? null);
		$this->assertTrue($property['nullable'] ?? false);
		$this->assertArrayHasKey('default', $property);
		$this->assertNull($property['default']);
		$this->assertNotContains('beeldmateriaalConsentReviewDueAt', $schema['required'] ?? []);

	}//end testLearnerProfileGainsConsentReviewDueDate()
}//end class
