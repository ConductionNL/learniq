<?php

/**
 * Learniq EuropassIssuer learner lookup unit tests.
 *
 * Credential.learnerId is the LearnerProfile uuid (format uuid, $ref
 * LearnerProfile). The Europass form names the holder from that profile, so
 * the profile must be read by id; the former lookup treated the uuid as a
 * Nextcloud user id and matched it on `ncUserId`, which finds nothing.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\EdciPayloadBuilder;
use OCA\Learniq\Service\EuropassIssuer;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which profile names the holder of the Europass form.
 */
class EuropassIssuerLearnerTest extends TestCase {

	private const PROFILE = '0b6f7c1e-2d3a-4b5c-9d8e-7f6a5b4c3d2e';

	/**
	 * Every findAll() filter set the issuer sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The issuer over a store holding one profile, P. Ganpat.
	 *
	 * @return EuropassIssuer
	 */
	private function issuer(): EuropassIssuer {
		$profile = ['id' => self::PROFILE, 'ncUserId' => 'p.ganpat', 'givenName' => 'Priya', 'familyName' => 'Ganpat'];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($profile): ObjectEntity {
				if ($schema === 'learner-profile' && $id === self::PROFILE) {
					return OrEntityFactory::make($profile, 'learner-profile');
				}

				throw new DoesNotExistException('gone');
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($profile): array {
				$filters = ($config['filters'] ?? []);
				$this->queries[] = $filters;
				if (($filters['schema'] ?? '') === 'learner-profile' && ($filters['ncUserId'] ?? '') === 'p.ganpat') {
					return [OrEntityFactory::make($profile, 'learner-profile')];
				}

				return [];
			}
		);

		$signer = $this->createMock(CredentialSigningService::class);
		$signer->method('proofFor')->willReturn(['type' => 'DataIntegrityProof', 'jws' => 'a..b']);

		return new EuropassIssuer(
			objects: $objects,
			builder: new EdciPayloadBuilder(),
			signer: $signer,
			config: $this->createStub(IAppConfig::class)
		);
	}//end issuer()

	/**
	 * A credential whose learnerId is the profile uuid names that profile's
	 * learner, read by id and not matched on ncUserId.
	 *
	 * @return void
	 */
	public function testTheHolderIsTheProfileTheLearnerIdNames(): void {
		$payload = $this->issuer()->payloadFor(
			credential: ['id' => 'cred-1', 'kind' => 'certificate', 'learnerId' => self::PROFILE, 'learnerUserId' => 'p.ganpat', 'issuerDid' => 'did:web:x', 'tenant_id' => 't-1']
		);

		self::assertIsArray($payload);
		self::assertStringContainsString('Priya', (string)json_encode($payload['credentialSubject']));
		self::assertStringContainsString('Ganpat', (string)json_encode($payload['credentialSubject']));
		foreach ($this->queries as $filters) {
			self::assertArrayNotHasKey('ncUserId', $filters, 'A uuid learnerId is never looked up as a user id.');
		}
	}//end testTheHolderIsTheProfileTheLearnerIdNames()

	/**
	 * A legacy row whose learnerId held the user id still finds its profile
	 * through ncUserId.
	 *
	 * @return void
	 */
	public function testALegacyUserIdLearnerIdFallsBackToNcUserId(): void {
		$payload = $this->issuer()->payloadFor(
			credential: ['id' => 'cred-2', 'kind' => 'certificate', 'learnerId' => 'p.ganpat', 'issuerDid' => 'did:web:x', 'tenant_id' => 't-1']
		);

		self::assertIsArray($payload);
		self::assertStringContainsString('Priya', (string)json_encode($payload['credentialSubject']));
	}//end testALegacyUserIdLearnerIdFallsBackToNcUserId()
}//end class
