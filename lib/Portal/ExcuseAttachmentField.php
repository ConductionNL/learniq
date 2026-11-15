<?php

/**
 * Learniq absence report attachment field
 *
 * The attachment of an absence report as portaliq's file field. Without
 * `type: file` portaliq renders `attachmentRef` as a text box. With it,
 * portaliq creates the report first, then uploads the file into the report's
 * folder and writes the file id into `attachmentRef`. That property is a
 * string, so the field takes one file. Plain, like the provider: no portaliq
 * imports and no constructor dependencies.
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The file field config of an absence report's attachment.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
 */
class ExcuseAttachmentField {

	/**
	 * What the attachment may be: a doctor's note or a letter, as a document
	 * or a photo of one.
	 */
	public const ACCEPT = ['.pdf', '.jpg', '.jpeg', '.png', '.heic', '.doc', '.docx', '.odt'];

	/**
	 * The largest attachment, in megabytes.
	 */
	public const MAX_SIZE_MB = 10;

	/**
	 * The field config portaliq's FileFieldConfigNormaliser keeps.
	 *
	 * @return array<string, mixed> The field config.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
	 */
	public function config(): array {
		return [
			'type' => 'file',
			'label' => 'Attachment',
			'multiple' => false,
			'accept' => self::ACCEPT,
			'maxSizeMb' => self::MAX_SIZE_MB,
		];

	}//end config()
}//end class
