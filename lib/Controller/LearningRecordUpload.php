<?php

/**
 * Learniq learning-record upload in progress.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCP\IUser;

/**
 * One accepted learning-record upload while LearningRecordImportController
 * stores and records it. It never leaves that controller.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */
final class LearningRecordUpload {

	/**
	 * The authorised uploader.
	 *
	 * @var IUser
	 */
	public IUser $uploader;

	/**
	 * The Application the import is evidence for.
	 *
	 * @var string
	 */
	public string $applicationId = '';

	/**
	 * The requested source format.
	 *
	 * @var string
	 */
	public string $sourceFormat = '';

	/**
	 * The uploaded file's name.
	 *
	 * @var string
	 */
	public string $sourceFilename = '';

	/**
	 * Where PHP put the uploaded file.
	 *
	 * @var string
	 */
	public string $tmpPath = '';

	/**
	 * The uploader's tenant, once resolved.
	 *
	 * @var string
	 */
	public string $tenantId = '';

	/**
	 * The stored file's reference, once stored.
	 *
	 * @var string
	 */
	public string $sourceRef = '';
}//end class
