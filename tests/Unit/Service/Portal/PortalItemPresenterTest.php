<?php

/**
 * Learniq PortalItemPresenter unit tests.
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalItemPresenter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalItemPresenter.
 */
class PortalItemPresenterTest extends TestCase {

	/**
	 * A QTI 2.1 choice item whose correct answer is `SECRET_B`.
	 */
	private const QTI2_CHOICE = <<<'XML'
<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="q1" title="Capital">
  <responseDeclaration identifier="RESPONSE" cardinality="single" baseType="identifier">
    <correctResponse><value>SECRET_B</value></correctResponse>
  </responseDeclaration>
  <itemBody>
    <choiceInteraction responseIdentifier="RESPONSE" maxChoices="1">
      <prompt>What is the capital of the Netherlands?</prompt>
      <simpleChoice identifier="A">Rotterdam</simpleChoice>
      <simpleChoice identifier="SECRET_B">Amsterdam</simpleChoice>
      <simpleChoice identifier="C">Utrecht</simpleChoice>
    </choiceInteraction>
  </itemBody>
</assessmentItem>
XML;

	/**
	 * A QTI 3.0 match item.
	 */
	private const QTI3_MATCH = <<<'XML'
<qti-assessment-item xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0" identifier="q2">
  <qti-response-declaration identifier="RESPONSE" cardinality="multiple" baseType="directedPair">
    <qti-correct-response><qti-value>K1 T2</qti-value></qti-correct-response>
  </qti-response-declaration>
  <qti-item-body>
    <qti-match-interaction response-identifier="RESPONSE">
      <qti-prompt>Match the country to its capital.</qti-prompt>
      <qti-simple-match-set>
        <qti-simple-associable-choice identifier="K1">France</qti-simple-associable-choice>
        <qti-simple-associable-choice identifier="K2">Spain</qti-simple-associable-choice>
      </qti-simple-match-set>
      <qti-simple-match-set>
        <qti-simple-associable-choice identifier="T1">Madrid</qti-simple-associable-choice>
        <qti-simple-associable-choice identifier="T2">Paris</qti-simple-associable-choice>
      </qti-simple-match-set>
    </qti-match-interaction>
  </qti-item-body>
</qti-assessment-item>
XML;

	/**
	 * A QTI 3.0 essay item without a prompt element.
	 */
	private const QTI3_ESSAY = <<<'XML'
<qti-assessment-item xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0" identifier="q3">
  <qti-item-body>
    <p>Explain in your own words why the sky is blue.</p>
    <qti-extended-text-interaction response-identifier="RESPONSE"/>
  </qti-item-body>
</qti-assessment-item>
XML;

	/**
	 * A choice item becomes its prompt and options, in the drawn option order,
	 * with the drawn points and never the correct answer.
	 *
	 * @return void
	 */
	public function testAChoiceItemIsPresentedInTheDrawnOrderWithoutTheAnswer(): void {
		$presented = (new PortalItemPresenter())->present(
			item: ['id' => 'item-1', 'title' => 'Capital', 'interactionType' => 'choice', 'qtiBody' => self::QTI2_CHOICE, 'correctResponse' => 'SECRET_B', 'maxScore' => 1],
			drawnRef: ['itemId' => 'item-1', 'points' => 2, 'optionOrder' => ['C', 'A', 'SECRET_B']]
		);

		self::assertSame('item-1', $presented['itemId']);
		self::assertSame('choice', $presented['type']);
		self::assertSame('What is the capital of the Netherlands?', $presented['prompt']);
		self::assertSame(2, $presented['points']);
		self::assertSame(['C', 'A', 'SECRET_B'], array_column($presented['choices'], 'id'));
		self::assertSame(['Utrecht', 'Rotterdam', 'Amsterdam'], array_column($presented['choices'], 'label'));
		self::assertArrayNotHasKey('correctResponse', $presented);
	}//end testAChoiceItemIsPresentedInTheDrawnOrderWithoutTheAnswer()

	/**
	 * A QTI 3.0 match item yields sources and targets; the correct pairing is
	 * nowhere in the payload.
	 *
	 * @return void
	 */
	public function testAMatchItemYieldsSourcesAndTargets(): void {
		$presented = (new PortalItemPresenter())->present(
			item: ['id' => 'item-2', 'interactionType' => 'match', 'qtiBody' => self::QTI3_MATCH, 'maxScore' => 2],
			drawnRef: ['itemId' => 'item-2', 'points' => 2]
		);

		self::assertSame('Match the country to its capital.', $presented['prompt']);
		self::assertSame(['K1', 'K2'], array_column($presented['sources'], 'id'));
		self::assertSame(['T1', 'T2'], array_column($presented['targets'], 'id'));
		self::assertStringNotContainsString('K1 T2', (string)json_encode($presented));
	}//end testAMatchItemYieldsSourcesAndTargets()

	/**
	 * Without a prompt element the prompt is the body's own text.
	 *
	 * @return void
	 */
	public function testAnEssayPromptComesFromTheBody(): void {
		$presented = (new PortalItemPresenter())->present(
			item: ['id' => 'item-3', 'interactionType' => 'extendedText', 'qtiBody' => self::QTI3_ESSAY, 'maxScore' => 4],
			drawnRef: ['itemId' => 'item-3', 'points' => 4]
		);

		self::assertSame('Explain in your own words why the sky is blue.', $presented['prompt']);
		self::assertArrayNotHasKey('choices', $presented);
	}//end testAnEssayPromptComesFromTheBody()

	/**
	 * A body that is not XML never leaks: the prompt falls back to the title.
	 *
	 * @return void
	 */
	public function testAnUnreadableBodyFallsBackToTheTitle(): void {
		$presented = (new PortalItemPresenter())->present(
			item: ['id' => 'item-4', 'title' => 'Question four', 'interactionType' => 'textEntry', 'qtiBody' => '{"answer":"SECRET"}', 'maxScore' => 1],
			drawnRef: ['itemId' => 'item-4', 'points' => 1]
		);

		self::assertSame('Question four', $presented['prompt']);
		self::assertStringNotContainsString('SECRET', (string)json_encode($presented));
	}//end testAnUnreadableBodyFallsBackToTheTitle()
}//end class
