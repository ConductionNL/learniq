<?php

/**
 * Tests for PermissionAnswerDocument.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Service\Portal\PdfTarget;
use OCA\Learniq\Service\Portal\PermissionAnswerDocument;
use OCA\Learniq\Service\Portal\RecordPdf;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A guardian's answer becomes one PDF on the answer itself.
 */
class PermissionAnswerDocumentTest extends TestCase {

	/**
	 * What the renderer was asked to make.
	 *
	 * @var array<int, array{target: PdfTarget, data: array<string, mixed>}>
	 */
	private array $made = [];

	/**
	 * The document maker over a renderer that answers $made.
	 *
	 * @param bool                      $made    What the renderer answers.
	 * @param array<string, mixed>|null $profile The child's profile.
	 *
	 * @return PermissionAnswerDocument
	 */
	private function documents(bool $made = true, ?array $profile = ['fullName' => 'Vera Jansen']): PermissionAnswerDocument {
		$this->made = [];
		$pdf        = $this->createMock(originalClassName: RecordPdf::class);
		$pdf->method('make')->willReturnCallback(
			function (PdfTarget $target, array $data) use ($made): bool {
				$this->made[] = ['target' => $target, 'data' => $data];
				return $made;
			}
		);

		$profiles = $this->createMock(originalClassName: LearnerRefResolverInterface::class);
		$profiles->method('byRef')->willReturn($profile);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => 'nl:'.vsprintf($text, $params));

		return new PermissionAnswerDocument(pdf: $pdf, profiles: $profiles, l10n: $l10n);
	}//end documents()

	/**
	 * The answer, as stored.
	 *
	 * @param string $answer `yes` or `no`.
	 *
	 * @return array<string, mixed>
	 */
	private static function response(string $answer = 'yes'): array {
		return [
			'learnerRef' => 'vera',
			'answer' => $answer,
			'answeredAt' => '2026-08-28T19:12:00+02:00',
			'assuranceLevel' => 'basic',
		];
	}//end response()

	/**
	 * The request answered.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(): array {
		return ['title' => 'Schoolreis naar Artis', 'explanation' => 'We gaan met de bus.', 'happensOn' => '2026-10-22'];
	}//end request()

	/**
	 * Fatima's yes is rendered once, kept on the answer under a name the next
	 * answer replaces, and reads the request, the child and her answer.
	 *
	 * @return void
	 */
	public function testAnAnswerIsRenderedOntoItself(): void {
		$kept = $this->documents()->keep(responseId: 'pr-1', response: self::response(), request: self::request());

		self::assertTrue(condition: $kept);
		self::assertCount(expectedCount: 1, haystack: $this->made);
		$target = $this->made[0]['target'];
		self::assertSame(expected: PermissionAnswerDocument::TEMPLATE_SLUG, actual: $target->templateSlug);
		self::assertSame(expected: 'permission-response', actual: $target->schema);
		self::assertSame(expected: 'pr-1', actual: $target->recordId);
		self::assertSame(expected: 'nl:Your answer to "Schoolreis naar Artis".pdf', actual: $target->fileName);

		$data = $this->made[0]['data'];
		self::assertSame(expected: 'Schoolreis naar Artis', actual: $data['requestTitle']);
		self::assertSame(expected: '2026-10-22', actual: $data['happensOn']);
		self::assertSame(expected: 'Vera Jansen', actual: $data['learnerName']);
		self::assertSame(expected: 'nl:Yes, I give permission', actual: $data['answerText']);
		self::assertSame(expected: '2026-08-28T19:12:00+02:00', actual: $data['answeredAt']);
	}//end testAnAnswerIsRenderedOntoItself()

	/**
	 * A no reads as a no, a profile without a full name reads its given and
	 * family name, and an unreadable profile leaves the name empty.
	 *
	 * @return void
	 */
	public function testTheAnswerAndTheNameReadInWords(): void {
		$this->documents(profile: ['givenName' => 'Vera', 'familyName' => 'Jansen'])
			->keep(responseId: 'pr-1', response: self::response(answer: 'no'), request: self::request());
		self::assertSame(expected: 'nl:No, I do not give permission', actual: $this->made[0]['data']['answerText']);
		self::assertSame(expected: 'Vera Jansen', actual: $this->made[0]['data']['learnerName']);

		$this->documents(profile: null)->keep(responseId: 'pr-1', response: self::response(), request: self::request());
		self::assertSame(expected: '', actual: $this->made[0]['data']['learnerName']);
	}//end testTheAnswerAndTheNameReadInWords()

	/**
	 * Without the stored answer's id nothing is rendered, and a render that
	 * fails is reported as not kept.
	 *
	 * @return void
	 */
	public function testNothingIsKeptWithoutTheAnswerOrARender(): void {
		self::assertFalse(condition: $this->documents()->keep(responseId: '', response: self::response(), request: self::request()));
		self::assertSame(expected: [], actual: $this->made);

		self::assertFalse(condition: $this->documents(made: false)->keep(responseId: 'pr-1', response: self::response(), request: self::request()));
	}//end testNothingIsKeptWithoutTheAnswerOrARender()
}//end class
