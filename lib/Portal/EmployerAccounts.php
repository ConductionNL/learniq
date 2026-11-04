<?php

/**
 * Learniq EmployerAccounts
 *
 * Lists the portal's employer accounts that carry learniq's `organisationRef`
 * claim, with the portal organisation each belongs to. The accounts are
 * portaliq's `portalAccount` rows; they are read as the system, a page at a
 * time with a hard stop, for the tenancy report only. Nothing is written.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The employer accounts and the company each one claims.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */
class EmployerAccounts {

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'portalAccount';

	/**
	 * Rows per page; the listing pages until a short page comes back.
	 */
	private const PAGE = 500;

	/**
	 * A hard stop, so a store that ignores the offset can never loop forever.
	 */
	private const MAX_PAGES = 40;

	/**
	 * The most accounts one listing reads.
	 */
	public const CAP = (self::MAX_PAGES * self::PAGE);

	/**
	 * Whether the last listing ended at the cap rather than on a short page.
	 *
	 * @var boolean
	 */
	private bool $stoppedAtCap = false;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the accounts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Every employer account with a company claim.
	 *
	 * @return list<array{subjectRef: string, organisation: string, organisationRef: string}>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function withCompany(): array {
		$this->stoppedAtCap = false;
		$accounts = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'audience' => EmployerSitePages::AUDIENCE],
					'limit'   => self::PAGE,
					'offset'  => ($page * self::PAGE),
				],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($rows as $row) {
				$account = self::account(row: $row);
				if ($account !== null) {
					$accounts[] = $account;
				}
			}

			if (count($rows) < self::PAGE) {
				return $accounts;
			}
		}

		$this->stoppedAtCap = true;
		return $accounts;
	}//end withCompany()

	/**
	 * Whether the last listing stopped at CAP accounts, so more may exist.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function stoppedAtCap(): bool {
		return $this->stoppedAtCap;
	}//end stoppedAtCap()

	/**
	 * The row as an employer account with a company claim, or null.
	 *
	 * @param mixed $row An OpenRegister row.
	 *
	 * @return array{subjectRef: string, organisation: string, organisationRef: string}|null
	 */
	private static function account(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		// The store is not trusted to have applied the audience filter.
		if (is_array($row) === false || ($row['audience'] ?? null) !== EmployerSitePages::AUDIENCE) {
			return null;
		}

		$company = ($row['claims'][Application::APP_ID][EmployerSitePages::CLAIM] ?? null);
		if (is_string($company) === false || trim($company) === '') {
			return null;
		}

		return [
			'subjectRef'      => (string)($row['subjectRef'] ?? ''),
			'organisation'    => (string)($row['organisation'] ?? ''),
			'organisationRef' => trim($company),
		];
	}//end account()
}//end class
