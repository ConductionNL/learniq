<?php

/**
 * Every notification rule's group recipients are groups that exist.
 *
 * About twenty rules in `x-openregister-notifications` named role words
 * (`coordinator`, `mentor`, `examboard`, `exam-board`, `study-advisor`,
 * `compliance-officer`) that no install provisions as a group, so the rule
 * fired and reached nobody. The groups an install has are the ones the
 * register declares as oauth2 scopes, plus Nextcloud's `admin`
 * (notification-recipients-provisioned).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Pins notification group recipients to declared, reading groups.
 */
class NotificationRecipientGroupsAreDeclaredTest extends TestCase {

	/**
	 * The parsed register.
	 *
	 * @var array<string, mixed>
	 */
	private array $register = [];

	/**
	 * Load the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);
	}//end setUp()

	/**
	 * Every group recipient, as "Schema.rule" => group names.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function groupRecipients(): array {
		$found = [];
		foreach ($this->register['components']['schemas'] as $name => $schema) {
			foreach (($schema['x-openregister-notifications'] ?? []) as $rule => $notification) {
				foreach (($notification['recipients'] ?? []) as $recipient) {
					if (($recipient['kind'] ?? '') === 'groups') {
						$found[$name . '.' . $rule] = array_merge($found[$name . '.' . $rule] ?? [], (array)$recipient['groups']);
					}
				}
			}
		}

		return $found;
	}//end groupRecipients()

	/**
	 * The test reads real rules, so an empty scan cannot pass.
	 *
	 * @return void
	 */
	public function testTheScanFindsGroupRecipients(): void {
		self::assertGreaterThanOrEqual(15, count($this->groupRecipients()));
	}//end testTheScanFindsGroupRecipients()

	/**
	 * Every group a rule names is a declared scope or `admin`.
	 *
	 * @return void
	 */
	public function testEveryGroupRecipientIsADeclaredGroup(): void {
		$scopes = ($this->register['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] ?? []);
		$declared = [...array_keys($scopes), 'admin'];

		$undeclared = [];
		foreach ($this->groupRecipients() as $rule => $groups) {
			foreach (array_diff($groups, $declared) as $group) {
				$undeclared[] = $rule . ' => ' . $group;
			}
		}

		self::assertSame([], $undeclared, 'Notification rules name groups no install provisions.');
	}//end testEveryGroupRecipientIsADeclaredGroup()

	/**
	 * A group told about an object can open it: where the schema lists who
	 * reads it, every recipient group (other than admin) is on that list.
	 *
	 * @return void
	 */
	public function testEveryGroupRecipientCanReadTheObject(): void {
		$blind = [];
		foreach ($this->groupRecipients() as $rule => $groups) {
			$schema = $this->register['components']['schemas'][explode('.', $rule)[0]];
			$read = ($schema['authorization']['read'] ?? null);
			if (is_array($read) === false || in_array('authenticated', $read, true) === true) {
				continue;
			}

			foreach (array_diff($groups, $read, ['admin']) as $group) {
				$blind[] = $rule . ' => ' . $group;
			}
		}

		self::assertSame([], $blind, 'Notification rules tell groups about objects they cannot read.');
	}//end testEveryGroupRecipientCanReadTheObject()
}//end class
