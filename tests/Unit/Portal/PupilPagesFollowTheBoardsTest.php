<?php

/**
 * The pupil's overview and grades follow the Vaartveld boards (MijnOverzicht, MijnLijst).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vaartveld-pupil-pages-follow-the-boards/specs/portal-contribution/spec.md#requirement-the-pupil-pages-follow-the-vaartveld-boards
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Reads the student contribution the real provider builds.
 */
class PupilPagesFollowTheBoardsTest extends TestCase {

	/**
	 * The student's pages by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pages(): array {
		return array_column((new PortalContributionProvider())->getContribution(['audience' => 'student'])['pages'], null, 'id');
	}//end pages()

	/**
	 * The overview: the date with its week, the timetable on the left with
	 * "Hele week" in its heading, homework and grades as rows on the right with
	 * a link under each, the absence as one tinted strip, and no buttons or
	 * messages (the board has neither).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vaartveld-pupil-pages-follow-the-boards/specs/portal-contribution/spec.md#requirement-the-pupil-pages-follow-the-vaartveld-boards
	 */
	public function testTheOverviewHasTheBoardsColumnsAndStrip(): void {
		$blocks = self::pages()['studentOverview']['blocks'];

		self::assertSame(['type' => 'greeting', 'showWeek' => true], $blocks[0]);
		self::assertSame(['main', 'line', ['label' => 'Whole week', 'page' => 'studentSessions']], [$blocks[1]['column'], $blocks[1]['frame'], $blocks[1]['more']]);

		$homework = $blocks[2];
		self::assertSame(['studentHomework', 'rows', 'side', 'line', 4], [$homework['collection'], $homework['display'], $homework['column'], $homework['frame'], $homework['limit']]);
		self::assertSame(['dueAt', 'eyebrow', 'asc'], [$homework['dateField'], $homework['dateDisplay'], $homework['sort']['direction']]);
		self::assertSame(['label' => 'Everything this week', 'page' => 'studentHomework', 'placement' => 'end'], $homework['more']);

		$grades = $blocks[3];
		self::assertSame(['studentGrades', 'rows', 'side', ['courseName'], 'value'], [$grades['collection'], $grades['display'], $grades['column'], $grades['titleFields'], $grades['valueField']]);
		self::assertSame(['gradedAt', 'desc', 3], [$grades['sort']['field'], $grades['sort']['direction'], $grades['limit']]);
		self::assertSame('All grades', $grades['more']['label']);

		$strip = $blocks[4];
		self::assertSame(['kpi', 'strip', 'tinted', 'studentExcuseRequests'], [$strip['type'], $strip['display'], $strip['frame'], $strip['more']['page']]);
		self::assertSame(['ill', 'late', 'without a report'], array_column($strip['cards'], 'stripLabel'));
		self::assertSame([], array_values(array_filter(array_column($strip['cards'], 'highlight'))), 'no card is marked: the board draws one grey line');

		$types = array_column($blocks, 'type');
		self::assertNotContains('cta', $types);
		self::assertNotContains('inbox', $types);
		self::assertNotContains('tasks', $types);
	}//end testTheOverviewHasTheBoardsColumnsAndStrip()

	/**
	 * The grades page: grades grouped per subject as chips with the average,
	 * a grade under 5,5 marked, the summary on top and tabs for the period,
	 * the year and the school exam, over fields the collection projects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vaartveld-pupil-pages-follow-the-boards/specs/portal-contribution/spec.md#requirement-the-pupil-pages-follow-the-vaartveld-boards
	 */
	public function testTheGradesAreGroupedPerSubject(): void {
		$contribution = (new PortalContributionProvider())->getContribution(['audience' => 'student']);
		$page         = array_column($contribution['pages'], null, 'id')['studentGrades'];
		self::assertCount(1, $page['blocks'], 'one block: no table and no raw detail panel');
		$block = $page['blocks'][0];

		self::assertSame(['chips', 'courseName', 'value', 5.5, true], [$block['display'], $block['groupField'], $block['valueField'], $block['lowBelow'], $block['summary']]);
		self::assertSame(['Period 1', 'Whole school year', 'School exam'], array_column($block['tabs'], 'label'));
		self::assertSame(['1'], $block['tabs'][0]['values']);

		$fields = array_column($contribution['collections'], null, 'id')['studentGrades']['fields'];
		foreach (['groupField', 'valueField', 'dateField', 'weightField', 'rowIdField'] as $key) {
			self::assertContains($block[$key], $fields, $key . ' is projected');
		}

		self::assertContains($block['tabs'][0]['field'], $fields);
		self::assertStringContainsString('{pass}', $block['summaryText']);
		self::assertStringContainsString('{fail}', $block['summaryText']);
	}//end testTheGradesAreGroupedPerSubject()
	/**
	 * In Dutch the strip reads "ziek", "te laat", "zonder melding", the links
	 * "Hele week", "Alles van deze week", "Alle cijfers", "Bekijken", and the
	 * grades tabs keep the stored values they filter on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vaartveld-pupil-pages-follow-the-boards/specs/portal-contribution/spec.md#requirement-the-pupil-pages-follow-the-vaartveld-boards
	 */
	public function testTheBoardWordsArriveInDutch(): void {
		$dutch = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/l10n/nl.json'), true)['translations'];
		$l10n  = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => ($dutch[$text] ?? $text));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('learniq')->willReturn($l10n);

		$pages  = array_column((new PortalContributionProvider(l10nFactory: $factory))->getContribution(['audience' => 'student'])['pages'], null, 'id');
		$blocks = $pages['studentOverview']['blocks'];
		self::assertSame(['Hele week', 'Alles van deze week', 'Alle cijfers', 'Bekijken'], [$blocks[1]['more']['label'], $blocks[2]['more']['label'], $blocks[3]['more']['label'], $blocks[4]['more']['label']]);
		self::assertSame(['ziek', 'te laat', 'zonder melding'], array_column($blocks[4]['cards'], 'stripLabel'));

		$grades = $pages['studentGrades']['blocks'][0];
		self::assertSame(['Periode 1', 'Heel het schooljaar', 'Schoolexamen'], array_column($grades['tabs'], 'label'));
		self::assertSame([['1'], ['SE']], [$grades['tabs'][0]['values'], $grades['tabs'][2]['values']]);
		self::assertSame('Je staat {pass} vakken voldoende en {fail} onvoldoende.', $grades['summaryText']);
	}//end testTheBoardWordsArriveInDutch()
}//end class
