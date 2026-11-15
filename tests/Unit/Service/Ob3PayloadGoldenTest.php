<?php

/**
 * Golden master for the Open Badges 3.0 payload.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CredentialSigningService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Each case builds one payload and compares it whole with the recording.
 * Set GOLDEN_RECORD=1 to record.
 *
 * @covers \OCA\Learniq\Service\CredentialSigningService
 */
class Ob3PayloadGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/ob3-payload-golden.json';

	/**
	 * Credential and issuer combinations.
	 *
	 * @return array<string, array{0: array<string, ?string>, 1: array<string, string>}>
	 */
	public static function cases(): array {
		$cases = [];
		$learners = ['uuid' => '0b9f1c52-6d2e-4a7b-9c3d-1e2f3a4b5c6d', 'email' => 'lea@example.test', 'uid' => 'lea'];
		foreach ($learners as $learnerKind => $learner) {
			foreach (['course' => 'c-7', 'manual' => null] as $courseKind => $course) {
				foreach (['expires' => '2027-01-01T00:00:00+00:00', 'open' => null] as $expiryKind => $expires) {
					$cases[implode('|', [$learnerKind, $courseKind, $expiryKind])] = [
						['credentialId' => 'cred-9', 'learnerId' => $learner, 'courseId' => $course, 'issuedAt' => '2026-06-01T10:00:00+00:00', 'expiresAt' => $expires],
						['issuerDid' => 'did:web:learniq:t1:ab', 'issuedBy' => 'Het Esdoornveen', 'verificationUrl' => 'https://example.test/verify/cred-9'],
					];
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The payload matches the recording.
	 *
	 * @param array<string, ?string> $credential The credential.
	 * @param array<string, string>  $issuer     The issuer.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testThePayloadMatchesTheRecording(array $credential, array $issuer): void {
		$service = (new ReflectionClass(CredentialSigningService::class))->newInstanceWithoutConstructor();
		$payload = $service->buildOb3Payload(credential: $credential, issuer: $issuer);

		$key = $this->dataName();
		$recorded = is_file(self::FIXTURE) === true ? json_decode((string)file_get_contents(self::FIXTURE), true) : [];
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$key] = json_encode($payload);
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT) . "\n");
		}

		self::assertSame($recorded[$key] ?? null, json_encode($payload));
	}//end testThePayloadMatchesTheRecording()
}//end class
