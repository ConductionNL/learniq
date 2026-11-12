<?php

/**
 * Learniq EDCI Credential Source
 *
 * What one European Digital Credential is built from, as
 * EdciPayloadBuilder::build() was given it: the credential, its course, the
 * learner, the issuing organisation and the course's competencies, plus the
 * credential urn and issuer DID read from the credential. Built and read only
 * inside EdciPayloadBuilder, for the length of one build.
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

/**
 * The sources of one Europass payload.
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */
final class EdciCredentialSource {

	/**
	 * The Credential (id, kind, issuedAt, expiresAt, issuerDid, tenant_id).
	 *
	 * @var array<string, mixed>
	 */
	public array $credential;

	/**
	 * The Course, or null for a credential without one.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $course;

	/**
	 * The LearnerProfile (givenName, familyName), or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $learner;

	/**
	 * `name`, optional `identifier` (KvK or BRIN) and `identifierScheme`.
	 *
	 * @var array<string, mixed>
	 */
	public array $issuer;

	/**
	 * Competencies of the course (id, title), for learning outcomes.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $competences;

	/**
	 * The credential urn.
	 *
	 * @var string
	 */
	public string $credentialId;

	/**
	 * The issuer DID.
	 *
	 * @var string
	 */
	public string $issuerId;
}//end class
