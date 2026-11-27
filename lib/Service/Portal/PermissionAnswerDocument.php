<?php

/**
 * Learniq PermissionAnswerDocument
 *
 * Keeps a guardian's answer to a permission request as a document of the
 * child: one PDF on the PermissionResponse, rendered through filinq when she
 * answers, so her consent shows up among the child's documents on the
 * portal ("Your answer to ...", with the request, the answer and the moment).
 * A new answer replaces the file, because it carries the same name. A render
 * that fails leaves the answer as it is: the answer counts, the document is
 * its copy.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCP\IL10N;

/**
 * Renders one permission answer onto its PermissionResponse.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */
class PermissionAnswerDocument {

	/**
	 * The render template of the answer, in filinq.
	 */
	public const TEMPLATE_SLUG = 'permission-answer';

	/**
	 * The schema the answer and its document live on.
	 */
	private const RESPONSE_SCHEMA = 'permission-response';

	/**
	 * Constructor.
	 *
	 * @param RecordPdf                   $pdf      Renders the document and keeps it on the answer.
	 * @param LearnerRefResolverInterface $profiles The child's profile, for the name on the document.
	 * @param IL10N                       $l10n     The document's words.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RecordPdf $pdf,
		private readonly LearnerRefResolverInterface $profiles,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Render the stored answer and keep the PDF on it.
	 *
	 * @param string               $responseId The stored PermissionResponse's uuid.
	 * @param array<string, mixed> $response   The answer as stored.
	 * @param array<string, mixed> $request    The PermissionRequest it answers.
	 *
	 * @return bool Whether the document was made and kept.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public function keep(string $responseId, array $response, array $request): bool {
		if ($responseId === '') {
			return false;
		}

		$requestTitle = trim((string)($request['title'] ?? ''));
		$title        = $this->l10n->t('Your answer to "%s"', [$requestTitle]);

		return $this->pdf->make(
			target: new PdfTarget(
				templateSlug: self::TEMPLATE_SLUG,
				schema: self::RESPONSE_SCHEMA,
				recordId: $responseId,
				fileName: $title.'.pdf'
			),
			data: [
				'title' => $title,
				'requestTitle' => $requestTitle,
				'explanation' => (string)($request['explanation'] ?? ''),
				'happensOn' => (string)($request['happensOn'] ?? ''),
				'learnerName' => $this->learnerName(learnerRef: (string)($response['learnerRef'] ?? '')),
				'answer' => (string)($response['answer'] ?? ''),
				'answerText' => $this->answerText(answer: (string)($response['answer'] ?? '')),
				'answeredAt' => (string)($response['answeredAt'] ?? ''),
				'assuranceLevel' => (string)($response['assuranceLevel'] ?? ''),
			]
		);
	}//end keep()

	/**
	 * The answer in words.
	 *
	 * @param string $answer `yes` or `no`.
	 *
	 * @return string
	 */
	private function answerText(string $answer): string {
		if ($answer === 'yes') {
			return $this->l10n->t('Yes, I give permission');
		}

		return $this->l10n->t('No, I do not give permission');
	}//end answerText()

	/**
	 * The child's name as the school knows it, or '' when the profile cannot be read.
	 *
	 * @param string $learnerRef The child's learner profile uuid.
	 *
	 * @return string
	 */
	private function learnerName(string $learnerRef): string {
		$profile = $this->profiles->byRef(learnerRef: $learnerRef);
		if ($profile === null) {
			return '';
		}

		$full = trim((string)($profile['fullName'] ?? ''));
		if ($full !== '') {
			return $full;
		}

		return trim((string)($profile['givenName'] ?? '').' '.(string)($profile['familyName'] ?? ''));
	}//end learnerName()
}//end class
