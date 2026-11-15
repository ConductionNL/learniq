<?php

/**
 * The Session schema's online meeting link, validated the way OpenRegister
 * validates a write: the shipped register fragment through Opis JSON Schema.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * timetabling-online-lesson-link: only an https link is stored on a lesson.
 */
class SessionOnlineMeetingUrlRegisterTest extends TestCase {
	/**
	 * The shipped Session schema, as JSON for the validator.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Read the shipped register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$schemas = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];
		$this->schema = (string)json_encode($this->validatable(schema: $schemas['Session']));
	}//end setUp()

	/**
	 * A lesson as a teacher saves it, with the given link.
	 *
	 * @param mixed $link The link; null leaves the property out, as a lesson without a link is saved.
	 *
	 * @return bool Whether the real schema accepts the write.
	 */
	private function accepts(mixed $link): bool {
		$payload = [
			'title' => 'Wiskunde',
			'startsAt' => '2026-10-06T08:30:00+00:00',
			'endsAt' => '2026-10-06T09:20:00+00:00',
			'cohortId' => '0b8f3c2e-4d6a-4f1b-9c3e-2a7d5e8f1b60',
			'tenant_id' => '00000000-0000-4000-8000-000000000001',
		];
		if ($link !== null) {
			$payload['onlineMeetingUrl'] = $link;
		}

		return (new Validator())->validate(json_decode((string)json_encode($payload)), $this->schema)->isValid();
	}//end accepts()

	/**
	 * An https link, and a lesson without one, are stored.
	 *
	 * @return void
	 */
	public function testAnHttpsLinkOrNoLinkIsStored(): void {
		self::assertTrue($this->accepts('https://meet.example.org/les-3b?pwd=x'));
		self::assertTrue($this->accepts(null));
	}//end testAnHttpsLinkOrNoLinkIsStored()

	/**
	 * A javascript:, http, data: or relative link is refused.
	 *
	 * @return void
	 */
	public function testAnUnsafeLinkIsRefused(): void {
		foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'http://meet.example.org/x', 'data:text/html,hi', '/apps/spreed', 'https://', ' https://meet.example.org'] as $link) {
			self::assertFalse($this->accepts($link), $link);
		}
	}//end testAnUnsafeLinkIsRefused()

	/**
	 * The property carries a title and a description for the generic forms.
	 *
	 * @return void
	 */
	public function testThePropertyIsLabelled(): void {
		$property = json_decode($this->schema, true)['properties']['onlineMeetingUrl'];
		self::assertNotSame('', (string)($property['title'] ?? ''));
		self::assertNotSame('', (string)($property['description'] ?? ''));
	}//end testThePropertyIsLabelled()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator cannot resolve.
	 *
	 * @param array<string,mixed> $schema The schema.
	 *
	 * @return array<string,mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		return $clean;
	}//end validatable()
}//end class
