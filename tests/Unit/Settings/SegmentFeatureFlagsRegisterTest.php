<?php

/**
 * Unit tests for the `LearniqSettings` register-JSON declaration.
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE, mirroring SchoolAndLocationRegisterTest. It deliberately
 * does NOT test any visibleIf/menu-gating behaviour on `segment`, because
 * this change does not add any (see openspec/changes/segment-feature-flags/
 * proposal.md Out of Scope and design.md Discovery: manifest.runtime has no
 * generic settings-to-runtime bridge, so declaring that gating now would
 * ship broken).
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
 * @spec openspec/changes/segment-feature-flags/tasks.md#task-1-add-the-learniqsettings-schema
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the LearniqSettings schema shape.
 */
class SegmentFeatureFlagsRegisterTest extends TestCase {

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
	 * `LearniqSettings` is a flat, un-lifecycled singleton (mirroring
	 * `SovereigntyPolicy`) declaring a `segment` enum defaulting to
	 * `corporate` (the no-behaviour-change default), with a
	 * pattern-matched slug free of the D07 camelCase-to-kebab `$ref`
	 * mismatch (single lowercase word, no separators).
	 *
	 * @return void
	 */
	public function testLearniqSettingsIsAFlatSingletonDefaultingToCorporate(): void {
		$schema = $this->config['components']['schemas']['LearniqSettings'];

		self::assertSame('learniqsettings', $schema['slug']);
		self::assertArrayNotHasKey('x-openregister-lifecycle', $schema);
		self::assertFalse($schema['x-openregister']['searchable']);
		self::assertSame(['segment'], $schema['required']);

		$segment = $schema['properties']['segment'];
		self::assertSame(['po', 'vo', 'mbo', 'he', 'corporate'], $segment['enum']);
		self::assertSame('corporate', $segment['default']);

	}//end testLearniqSettingsIsAFlatSingletonDefaultingToCorporate()

	/**
	 * `setBy`/`setAt` mirror `SovereigntyPolicy`'s nullable traceability
	 * fields.
	 *
	 * @return void
	 */
	public function testSetByAndSetAtAreNullableTraceabilityFields(): void {
		$schema = $this->config['components']['schemas']['LearniqSettings'];

		self::assertTrue($schema['properties']['setBy']['nullable']);
		self::assertNull($schema['properties']['setBy']['default']);

		self::assertTrue($schema['properties']['setAt']['nullable']);
		self::assertSame('date-time', $schema['properties']['setAt']['format']);

	}//end testSetByAndSetAtAreNullableTraceabilityFields()
}//end class
