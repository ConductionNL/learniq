<?php

/**
 * Learniq PortalRecordProviders
 *
 * The services behind the provider methods portaliq calls for one row the
 * resident owns: who she may write to about it, and what she may download.
 * One object, so the contribution provider does not grow a parameter per
 * service.
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
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Holds the per-row services of the contribution provider.
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */
class PortalRecordProviders {

	/**
	 * Constructor.
	 *
	 * @param PortalMessageContacts|null $contacts  Who a resident may write to (portal-message-contacts).
	 * @param GuardianDocuments|null     $documents What a guardian may download (guardian-reports-and-documents).
	 */
	public function __construct(
		public readonly ?PortalMessageContacts $contacts=null,
		public readonly ?GuardianDocuments $documents=null,
	) {
	}//end __construct()
}//end class
