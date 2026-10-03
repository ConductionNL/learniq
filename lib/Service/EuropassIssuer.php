<?php

/**
 * Learniq Europass Issuer
 *
 * Gives a signed credential its Europass form (credentials-europass-edci-export):
 * reads the course, the learner's name, the course's competencies and the
 * issuer's legal identifier, builds the ELM payload with EdciPayloadBuilder and
 * signs it with the same tenant key and key id as the Open Badges payload
 * (CredentialSigningService::proofFor()). Used at issue time by
 * CredentialIssuanceHandler and for an earlier credential by
 * CredentialEuropassController.
 *
 * Reads run as the system: issuing runs from a listener with no session, and
 * the backfill route has checked its caller before it gets here.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * Builds and signs the Europass form of a credential.
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */
class EuropassIssuer {

	private const REGISTER = 'learniq';

	/**
	 * A LearnerProfile uuid, what Credential.learnerId holds.
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * App config key of the issuer's legal identifier (KvK number or BRIN).
	 */
	public const IDENTIFIER_KEY = 'europass_issuer_identifier';

	/**
	 * App config key of the identifier's scheme name, default `KvK`.
	 */
	public const SCHEME_KEY = 'europass_issuer_identifier_scheme';

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objects OpenRegister object access.
	 * @param EdciPayloadBuilder       $builder The ELM mapping.
	 * @param CredentialSigningService $signer  The tenant key and proof.
	 * @param IAppConfig               $config  The issuer's legal identifier.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly EdciPayloadBuilder $builder,
		private readonly CredentialSigningService $signer,
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * The credential with a signed `edciPayload`, when its kind gets one and
	 * the tenant can sign; otherwise the credential unchanged.
	 *
	 * @param array<string, mixed> $credential A signed credential (id, issuerDid, tenant_id set).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-a-training-certificate-gets-its-europass-form
	 */
	public function withEuropass(array $credential): array {
		if (in_array(($credential['kind'] ?? ''), EdciPayloadBuilder::KINDS, true) === false) {
			return $credential;
		}

		$payload = $this->payloadFor(credential: $credential);
		if ($payload === null) {
			return $credential;
		}

		$credential['edciPayload'] = $payload;

		return $credential;
	}//end withEuropass()

	/**
	 * The signed Europass payload of a credential, or null when it cannot be
	 * signed.
	 *
	 * @param array<string, mixed> $credential A signed credential.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function payloadFor(array $credential): ?array {
		$course = $this->read(schema: 'course', id: (string)($credential['courseId'] ?? ''));
		$payload = $this->builder->build(
			credential: $credential,
			course: $course,
			learner: $this->learner(learnerId: (string)($credential['learnerId'] ?? '')),
			issuer: $this->issuer(credential: $credential),
			competences: $this->competences(ids: (array)($course['competencyIds'] ?? []))
		);

		$proof = $this->signer->proofFor(
			payload: $payload,
			tenantId: (string)($credential['tenant_id'] ?? ''),
			issuerDid: (string)($credential['issuerDid'] ?? '')
		);
		if ($proof === null) {
			return null;
		}

		$payload['proof'] = $proof;

		return $payload;
	}//end payloadFor()

	/**
	 * The issuer: the credential's `issuedBy`, and a legal identifier from app
	 * config (a KvK number, for a company without a BRIN) or else the BRIN of
	 * the tenant's School record.
	 *
	 * @param array<string, mixed> $credential The credential.
	 *
	 * @return array<string, mixed>
	 */
	private function issuer(array $credential): array {
		$issuer = [
			'name' => $credential['issuedBy'] ?? null,
			'identifier' => $this->config->getValueString(app: Application::APP_ID, key: self::IDENTIFIER_KEY, default: ''),
			'identifierScheme' => $this->config->getValueString(app: Application::APP_ID, key: self::SCHEME_KEY, default: 'KvK'),
		];
		if ($issuer['identifier'] !== '') {
			return $issuer;
		}

		$school = $this->first(filters: ['schema' => 'school', 'tenant_id' => (string)($credential['tenant_id'] ?? '')]);
		if (is_string($school['brin'] ?? null) === true && $school['brin'] !== '') {
			$issuer['identifier'] = $school['brin'];
			$issuer['identifierScheme'] = 'BRIN';
		}

		return $issuer;
	}//end issuer()

	/**
	 * The first learniq row matching the filters, or null.
	 *
	 * @param array<string, string> $filters `schema` and property filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(array $filters): ?array {
		$rows = $this->objects->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER], $filters), 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		if ($rows === []) {
			return null;
		}

		$row = $rows[0];
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end first()

	/**
	 * The learner's profile (name only is used), or null.
	 *
	 * Credential.learnerId is the LearnerProfile uuid, so the profile is read
	 * by id. Only a legacy row whose learnerId is not a uuid (it held the
	 * Nextcloud user id) is looked up by `ncUserId`.
	 *
	 * @param string $learnerId The credential's learnerId: a LearnerProfile uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	private function learner(string $learnerId): ?array {
		if ($learnerId === '') {
			return null;
		}

		if (preg_match(self::UUID_PATTERN, $learnerId) === 1) {
			return $this->read(schema: 'learner-profile', id: $learnerId);
		}

		return $this->first(filters: ['schema' => 'learner-profile', 'ncUserId' => $learnerId]);
	}//end learner()

	/**
	 * The competencies of a course that exist, with their titles.
	 *
	 * @param array<int, mixed> $ids Competency uuids.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function competences(array $ids): array {
		$found = [];
		foreach ($ids as $id) {
			$competence = $this->read(schema: 'competency', id: (string)$id);
			if ($competence !== null) {
				$found[] = $competence;
			}
		}

		return $found;
	}//end competences()

	/**
	 * One learniq object by uuid as an array, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false, _render: false);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end read()
}//end class
