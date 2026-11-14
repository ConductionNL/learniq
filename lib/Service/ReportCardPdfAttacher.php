<?php

/**
 * Learniq Report Card PDF Attacher
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Copies the PDF filinq rendered for a report card onto the report card as an
 * OpenRegister file, so the card's own access rules and the portal's download
 * path serve it (report-card-pdf-to-guardians D2). filinq stores the rendered
 * PDF/A in the Files storage of its `report_render_storage_user` and answers a
 * Files file id, which the render bridge keeps as `docudeskDocumentRef`.
 *
 * One PDF per card (D3): the file is written by name (an upsert), and any
 * earlier `Rapport … .pdf` this flow attached under another name is removed.
 * Fail-soft like the render: a failure is logged and leaves the card as it is.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\ReportCardPdfAttacherInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Attaches a rendered report-card PDF to the report card.
 *
 * @spec openspec/changes/report-card-pdf-to-guardians/specs/report-card/spec.md#requirement-the-rendered-pdf-is-a-file-on-the-report-card
 */
class ReportCardPdfAttacher implements ReportCardPdfAttacherInterface {

	/**
	 * The app whose config names the user filinq stores rendered reports for.
	 *
	 * @var string
	 */
	private const FILINQ_APP = 'filinq';

	/**
	 * filinq's config key for that storage user.
	 *
	 * @var string
	 */
	private const STORAGE_USER_KEY = 'report_render_storage_user';

	/**
	 * Every file name this flow writes starts with this word and ends in `.pdf`.
	 *
	 * @var string
	 */
	private const NAME_PREFIX = 'Rapport';

	/**
	 * Tag on the attached file.
	 *
	 * @var string
	 */
	private const TAG = 'report-card-pdf';

	/**
	 * Constructor.
	 *
	 * @param FileService     $fileService OpenRegister file service, writes the file on the card.
	 * @param IRootFolder     $rootFolder  Files root, reads filinq's rendered PDF.
	 * @param IAppConfig      $appConfig   Reads filinq's storage user.
	 * @param IUserManager    $userManager Resolves the learner's display name for the file name.
	 * @param LoggerInterface $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FileService $fileService,
		private readonly IRootFolder $rootFolder,
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Attach the rendered PDF of a report card to the card.
	 *
	 * @param ObjectEntity $reportCard The report card, as saved after a successful render.
	 *
	 * @return string|null The attached file's name, or null when nothing was attached.
	 *
	 * @spec openspec/changes/report-card-pdf-to-guardians/specs/report-card/spec.md#requirement-the-rendered-pdf-is-a-file-on-the-report-card
	 */
	public function attach(ObjectEntity $reportCard): ?string {
		$card = $reportCard->getObject();
		$ref  = (string) ($card['docudeskDocumentRef'] ?? '');
		if (($card['docudeskRenderStatus'] ?? null) !== 'rendered' || ctype_digit($ref) === false) {
			return null;
		}

		try {
			$pdf = $this->renderedPdf(fileId: (int) $ref);
			if ($pdf === null) {
				$this->logger->warning(
					'[ReportCardPdfAttacher] The rendered PDF {ref} of ReportCard {id} was not found in filinq\'s storage.',
					['ref' => $ref, 'id' => $reportCard->getUuid()]
				);
				return null;
			}

			$name = $this->fileName(card: $card);
			$this->fileService->saveFile(
				objectEntity: $reportCard,
				fileName: $name,
				content: $pdf->getContent(),
				share: false,
				tags: [self::TAG]
			);
			$this->removeEarlierPdfs(reportCard: $reportCard, keep: $name);

			return $name;
		} catch (Throwable $e) {
			$this->logger->error(
				'[ReportCardPdfAttacher] Attaching the PDF to ReportCard {id} failed: {message}',
				['id' => $reportCard->getUuid(), 'message' => $e->getMessage(), 'exception' => $e]
			);
			return null;
		}//end try
	}//end attach()

	/**
	 * The file name: `Rapport {period} {learner}.pdf`.
	 *
	 * A period already named "Rapport …" is not prefixed twice. Path separators are
	 * replaced so the name stays one file name.
	 *
	 * @param array<string, mixed> $card The report card data.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/report-card-pdf-to-guardians/specs/report-card/spec.md#scenario-first-render
	 */
	private function fileName(array $card): string {
		$period = trim((string) ($card['periodName'] ?? ''));
		if (stripos($period, self::NAME_PREFIX) !== 0) {
			$period = trim(self::NAME_PREFIX.' '.$period);
		}

		$learnerId = (string) ($card['learnerId'] ?? '');
		$learner   = $learnerId;
		if ($learnerId !== '') {
			$learner = $this->userManager->get($learnerId)?->getDisplayName() ?? $learnerId;
		}

		$name = trim($period.' '.trim($learner));

		return str_replace(['/', '\\'], '-', $name).'.pdf';
	}//end fileName()

	/**
	 * The PDF filinq stored for this render, or null when it is not there.
	 *
	 * @param int $fileId The Files file id filinq answered.
	 *
	 * @return File|null
	 */
	private function renderedPdf(int $fileId): ?File {
		$storageUser = $this->appConfig->getValueString(self::FILINQ_APP, self::STORAGE_USER_KEY, '');
		if ($storageUser === '') {
			return null;
		}

		foreach ($this->rootFolder->getUserFolder($storageUser)->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end renderedPdf()

	/**
	 * Remove a PDF an earlier render attached under another name (one PDF per card).
	 *
	 * @param ObjectEntity $reportCard The report card.
	 * @param string       $keep       The name just written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-pdf-to-guardians/specs/report-card/spec.md#scenario-a-re-render-replaces-the-file
	 */
	private function removeEarlierPdfs(ObjectEntity $reportCard, string $keep): void {
		foreach ($this->fileService->getFiles(object: $reportCard) as $node) {
			$name = $node->getName();
			if ($name === $keep
				|| str_starts_with($name, self::NAME_PREFIX.' ') === false
				|| str_ends_with(strtolower($name), '.pdf') === false
			) {
				continue;
			}

			$this->fileService->deleteFile(file: $node, object: $reportCard);
		}
	}//end removeEarlierPdfs()
}//end class
