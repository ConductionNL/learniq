<?php

/**
 * Scholiq LearningRecordShareVerifyController auth-posture tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-shared-learning-record-is-verifiable-without-a-nextcloud-session
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\Scholiq\Controller\LearningRecordShareVerifyController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Locks the public verification surface's declared auth posture.
 *
 * This endpoint is deliberately unauthenticated: external employers and
 * receiving-school admissions offices call it with only a share link. Its
 * posture is therefore load-bearing in both directions — losing the
 * annotations breaks every external verifier, and gaining them somewhere
 * unintended opens a surface.
 */
class LearningRecordShareVerifyAuthPostureTest extends TestCase
{

    /**
     * Nextcloud's ControllerMethodReflector annotation pattern.
     *
     * `ControllerMethodReflector::reflect()` scans a docblock line by line with
     * this shape. Anything matching it is registered as an annotation — the
     * reflector has no notion of an @-word being "just prose".
     *
     * @var string
     */
    private const NC_ANNOTATION_RE = '/^\h+\*\h+@([A-Z]\w+)(.*)$/m';

    /**
     * verify() declares both annotations that make it reachable without a session.
     *
     * Asserted through the same regex Nextcloud itself uses, not with a loose
     * substring search: a `@PublicPage` mentioned mid-sentence would satisfy
     * str_contains() while NOT being an annotation, and this test would then be
     * green about a posture that does not exist.
     *
     * @return void
     */
    public function testVerifyDeclaresPublicPageAndNoCsrfRequired(): void
    {
        $doc = (new ReflectionMethod(LearningRecordShareVerifyController::class, 'verify'))->getDocComment();

        self::assertIsString($doc, 'verify() must carry a docblock — its auth posture is declared there');

        $matches = [];
        preg_match_all(self::NC_ANNOTATION_RE, $doc, $matches);
        $annotations = $matches[1];

        self::assertContains('PublicPage', $annotations);
        self::assertContains('NoCSRFRequired', $annotations);
    }//end testVerifyDeclaresPublicPageAndNoCsrfRequired()

    /**
     * No auth annotation sits at tag position outside a method docblock.
     *
     * Scans the RAW FILE, not a reflected docblock. That distinction is the
     * whole point: this controller's explanatory prose lives in the FILE-level
     * header comment, which `ReflectionClass::getDocComment()` does not return
     * — a first version of this test reflected the class docblock, and passed
     * unchanged when the defect was deliberately reinstated. It could not fail.
     *
     * Scope and honesty about it: because that prose is a file header rather
     * than a class or method docblock, Nextcloud's reflector would not have
     * read it either — so the wording this guards is a readability and
     * static-analysis hazard here, NOT a live authorization hole. It is worth
     * locking anyway, because the same sentence one docblock lower IS read: a
     * sibling app shipped `* @NoCSRFRequired removed to close ...` in a method
     * docblock and that sentence re-enabled the annotation on two admin POSTs.
     *
     * @return void
     */
    public function testNoAuthAnnotationSitsAtTagPositionOutsideAMethodDocblock(): void
    {
        $file = (new ReflectionClass(LearningRecordShareVerifyController::class))->getFileName();
        self::assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        // Line numbers covered by a method docblock — where these annotations
        // are legitimate and required.
        $legitimate = [];
        foreach ((new ReflectionClass(LearningRecordShareVerifyController::class))->getMethods() as $method) {
            $doc = $method->getDocComment();
            if ($doc === false) {
                continue;
            }

            $docLineCount = substr_count($doc, "\n") + 1;
            $docStart     = ($method->getStartLine() - $docLineCount);
            for ($i = $docStart; $i < $method->getStartLine(); $i++) {
                $legitimate[$i] = true;
            }
        }

        $offenders = [];
        foreach ($lines as $index => $line) {
            $lineNo = ($index + 1);
            if (isset($legitimate[$lineNo]) === true) {
                continue;
            }

            if (preg_match('/^\h+\*\h+@(PublicPage|NoCSRFRequired|NoAdminRequired|CORS)\b/', $line) === 1) {
                $offenders[] = $lineNo.': '.trim($line);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'An auth annotation sits at docblock-tag position outside a method docblock. Nextcloud matches '
            .'`^\h+\*\h+@([A-Z]\w+)(.*)$` and reads such a line AS the annotation, even mid-sentence. '
            .'Backtick it or move it off the start of the line. Found — '.implode(' | ', $offenders)
        );
    }//end testNoAuthAnnotationSitsAtTagPositionOutsideAMethodDocblock()
}//end class
