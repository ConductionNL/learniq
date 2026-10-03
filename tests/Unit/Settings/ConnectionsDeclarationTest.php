<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of learniq's Integrations page. Nothing in learniq reads it at runtime,
 * so a broken file fails nowhere in this repo: integriq skips it whole and the
 * page goes empty on some other instance. Every assertion here is a way that
 * file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integrations/spec.md#requirement-req-int-conn-001-learniq-declares-its-outside-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\ConnectionReportService;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against design D2 of connection-registry.
 *
 * The rules mirror integriq's `lib/Settings/connections.schema.json` field for
 * field, including the hydra#673 amendments (`adapter.jsonPath`,
 * `adapter.simulatedValues`, `reportedOnly`). That schema is not a dependency
 * of this repo, so the rules are restated here. The file was also validated
 * against the schema itself with Ajv when it was written.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * The fields the schema allows on one connection, with their JSON type.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'key' => 'string',
		'title' => 'string',
		'description' => 'string',
		'order' => 'integer',
		'settingsUrl' => 'string',
		'requiredConfig' => 'array',
		'adapter' => 'array',
		'reportedOnly' => 'boolean',
		'available' => 'boolean',
		'unavailableMessage' => 'string',
		'unconfiguredMessage' => 'string',
		'sourceTemplate' => 'string',
	];

	/**
	 * The declared keys, in page order. Keys are frozen once shipped.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = [
		'data-exchange',
		'timetable',
		'lti',
		'eudi-wallet',
		'payment',
		'sbb',
		'proctoring',
		'plagiarism',
	];

	/**
	 * The admin settings components an anchor may live in.
	 *
	 * @var array<int, string>
	 */
	private const ADMIN_PAGE_FILES = [
		'src/views/settings/AdminRoot.vue',
		'src/views/settings/DataExchangeSettingsSection.vue',
		'src/views/settings/TimetableExchangeSettingsSection.vue',
		'src/views/settings/LtiSettingsSection.vue',
		'src/views/LearniqSettings.vue',
	];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[(string)$connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The file names the app it ships in, and nothing else at the top level.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$declaration = $this->declaration();
		// Deliberately file_get_contents() + simplexml_load_string() rather than
		// simplexml_load_file(). Under the Nextcloud bootstrap lib/base.php calls
		// libxml_set_external_entity_loader() with a loader returning null, and
		// that resolver also handles the primary document, so load_file() returns
		// false for a well-formed info.xml. Parsing a string never touches it.
		$infoXml = simplexml_load_string(
			(string)file_get_contents($this->root() . '/appinfo/info.xml')
		);

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: 'learniq', actual: (string)$infoXml->id);
		$this->assertSame(expected: (string)$infoXml->id, actual: $declaration['app']);
		$this->assertSame(expected: ['app', 'connections'], actual: array_keys($declaration));
	}//end testTheFileNamesThisApp()

	/**
	 * The eight connections, in order, each key once.
	 *
	 * A row is keyed by app and key, so a second entry with the same key would
	 * overwrite the first, and a renamed key orphans a row.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueAndFrozen(): void {
		$keys = array_column($this->declaration()['connections'], 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys);
		$this->assertSame(expected: self::KEYS, actual: $keys);
	}//end testTheKeysAreUniqueAndFrozen()

	/**
	 * Every entry uses only schema fields with the schema's types, and a rising order.
	 *
	 * @return void
	 */
	public function testEveryEntryHasTheShapeIntegriqValidates(): void {
		$previousOrder = 0;
		foreach ($this->declaration()['connections'] as $connection) {
			$key = (string)$connection['key'];

			$this->assertSame(
				expected: [],
				actual: array_diff(array_keys($connection), array_keys(self::FIELD_TYPES)),
				message: $key . ' carries a field the schema does not allow'
			);
			foreach ($connection as $field => $value) {
				$this->assertSame(
					expected: self::FIELD_TYPES[$field],
					actual: $this->jsonType(value: $value),
					message: $key . '.' . $field . ' has the wrong type'
				);
			}

			$this->assertMatchesRegularExpression(pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/', string: $key);
			$this->assertNotSame(expected: '', actual: trim((string)($connection['title'] ?? '')), message: $key . ' has no title');
			$this->assertGreaterThan(expected: $previousOrder, actual: $connection['order'], message: $key . ' breaks the page order');
			$previousOrder = $connection['order'];
		}
	}//end testEveryEntryHasTheShapeIntegriqValidates()

	/**
	 * No text a reader sees carries an em-dash or a double dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: '--', haystack: $this->raw());
	}//end testNoTextCarriesAnEmDash()

	/**
	 * Every settings link lands on a section the admin page really has.
	 *
	 * A link into a section that does not exist opens the page at the top and
	 * logs nothing. Gate 116 checks the same rule on the whole of `src/`.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtAnExistingSection(): void {
		$adminPage = '';
		foreach (self::ADMIN_PAGE_FILES as $file) {
			$adminPage .= (string)file_get_contents($this->root() . '/' . $file);
		}

		$linked = [];
		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string)$connection['settingsUrl'];
			$this->assertStringStartsWith(prefix: '/settings/admin/learniq#section-', string: $url, message: $connection['key']);
			$anchor = substr($url, (strpos($url, '#') + 1));
			$this->assertStringContainsString(
				needle: 'id="' . $anchor . '"',
				haystack: $adminPage,
				message: $connection['key'] . ' links to a missing section'
			);
			$linked[] = $connection['key'];
		}

		$this->assertSame(expected: ['data-exchange', 'timetable', 'lti'], actual: $linked);
	}//end testEverySettingsLinkPointsAtAnExistingSection()

	/**
	 * A connection that cannot work says so, and says why.
	 *
	 * An unavailable row without a message would read "Declared, not built
	 * yet.", which is false for the four whose code is built and calls an
	 * endpoint integriq does not publish.
	 *
	 * @return void
	 */
	public function testEveryUnavailableConnectionSaysWhy(): void {
		$unavailable = [];
		foreach ($this->connectionsByKey() as $key => $connection) {
			if (($connection['available'] ?? true) === true) {
				continue;
			}

			$unavailable[] = $key;
			$this->assertNotSame(expected: '', actual: trim((string)($connection['unavailableMessage'] ?? '')), message: $key);
			$this->assertArrayNotHasKey(key: 'requiredConfig', array: $connection, message: $key . ' cannot become configured');
		}

		$this->assertSame(
			expected: ['sbb', 'proctoring', 'plagiarism'],
			actual: $unavailable
		);
	}//end testEveryUnavailableConnectionSaysWhy()

	/**
	 * LTI launches through integriq's typed event, and learniq reports the row
	 * from whether that event exists (content-lti-launch-through-integriq).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works
	 */
	public function testLtiLaunchesThroughIntegriqsEventAndIsReported(): void {
		$byKey = $this->connectionsByKey();

		$this->assertArrayNotHasKey(key: 'available', array: $byKey['lti']);
		$this->assertTrue($byKey['lti']['reportedOnly']);
		$this->assertContains(needle: 'lti', haystack: ConnectionReportService::REPORTED_KEYS);
		$controller = (string)file_get_contents($this->root() . '/lib/Controller/LtiToolPlacementController.php');
		$this->assertStringContainsString(needle: "'OCA\\Integriq\\Event\\LtiLaunchRequestedEvent'", haystack: $controller);
		$this->assertStringNotContainsString(needle: 'api/lti/deployments', haystack: $controller);
	}//end testLtiLaunchesThroughIntegriqsEventAndIsReported()

	/**
	 * Data exchange runs through integriq's events, not a route learniq calls
	 * (data-exchange-to-integriq); the timetable is planninq's (D10).
	 *
	 * @return void
	 */
	public function testDataExchangeRunsThroughIntegriqAndTheTimetableIsPlanninqs(): void {
		$byKey = $this->connectionsByKey();

		$this->assertTrue($byKey['data-exchange']['available']);
		$client = (string)file_get_contents($this->root() . '/lib/Service/IntegriqExchangeClient.php');
		$this->assertStringContainsString(needle: 'OCA\\\\Integriq\\\\Event\\\\ExchangeJobRequestedEvent', haystack: $client);
		// timetable-connection-and-import-screen: learniq reports the row from
		// whether planninq is enabled, so the row is not declared unavailable.
		$this->assertArrayNotHasKey(key: 'available', array: $byKey['timetable']);
		$this->assertTrue($byKey['timetable']['reportedOnly']);
		$this->assertStringContainsString(needle: 'planninq', haystack: (string)$byKey['timetable']['unconfiguredMessage']);
	}//end testDataExchangeRunsThroughIntegriqAndTheTimetableIsPlanninqs()

	/**
	 * Payments need the shillinq administration the raise sends.
	 *
	 * @return void
	 */
	public function testPaymentsRequireTheAdministrationTheRaiseSends(): void {
		$payment = $this->connectionsByKey()['payment'];

		$this->assertSame(expected: ['shillinq_administration_id'], actual: $payment['requiredConfig']);
		$this->assertArrayNotHasKey(key: 'available', array: $payment);
		$this->assertStringContainsString(
			needle: "ADMINISTRATION_KEY = 'shillinq_administration_id'",
			haystack: (string)file_get_contents($this->root() . '/lib/Service/ContributionRaiser.php')
		);
	}//end testPaymentsRequireTheAdministrationTheRaiseSends()

	/**
	 * The wallet needs the token every wallet call sends.
	 *
	 * @return void
	 */
	public function testTheWalletRequiresTheTokenItsCallsSend(): void {
		$wallet = $this->connectionsByKey()['eudi-wallet'];

		$this->assertSame(expected: ['openconnector_api_token'], actual: $wallet['requiredConfig']);
		$this->assertArrayNotHasKey(key: 'available', array: $wallet);
		$this->assertArrayNotHasKey(key: 'reportedOnly', array: $wallet);
		foreach (['lib/Service/WalletOfferDelegationService.php', 'lib/Service/WalletRevocationPropagationService.php'] as $file) {
			$this->assertStringContainsString(
				needle: "OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token'",
				haystack: (string)file_get_contents($this->root() . '/' . $file),
				message: $file
			);
		}
	}//end testTheWalletRequiresTheTokenItsCallsSend()

	/**
	 * Every connection learniq reports on is declared and can take a report.
	 *
	 * Integriq refuses a report for an undeclared key, and rule 2 of the
	 * status resolver ignores a report on an unavailable row.
	 *
	 * @return void
	 */
	public function testEveryReportedConnectionIsDeclaredAndAvailable(): void {
		$byKey = $this->connectionsByKey();
		foreach (ConnectionReportService::REPORTED_KEYS as $key) {
			$this->assertArrayHasKey(key: $key, array: $byKey);
			$this->assertTrue(condition: ($byKey[$key]['available'] ?? true), message: $key);
		}
	}//end testEveryReportedConnectionIsDeclaredAndAvailable()

	/**
	 * The JSON type name of a decoded value.
	 *
	 * @param mixed $value The decoded value.
	 *
	 * @return string
	 */
	private function jsonType(mixed $value): string {
		return match (true) {
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_string($value) => 'string',
			is_array($value) => 'array',
			default => get_debug_type($value),
		};
	}//end jsonType()
}//end class
