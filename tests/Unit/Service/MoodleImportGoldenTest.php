<?php

/**
 * Learniq Moodle backup import golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\MoodleBackupParser;
use OCA\Learniq\Service\MoodleQuizQuestionMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins MoodleBackupParser::parseManifest() and
 * MoodleQuizQuestionMapper::mapQuestions() for a set of files against
 * tests/Unit/Service/golden/MoodleImport.json. With RECORD_GOLDEN=1 the file
 * is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\MoodleBackupParser
 * @covers \OCA\Learniq\Service\MoodleQuizQuestionMapper
 * @uses \OCA\Learniq\Service\XmlChildText
 */
class MoodleImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/MoodleImport.json';

	/**
	 * Every output equals the recorded golden.
	 *
	 * @return void
	 */
	public function testImportMatchesGolden(): void {
		$manifests = [
			'full' => '<moodle_backup><information><contents><activities>'
				. '<activity><moduleid>11</moduleid><sectionid>1</sectionid><modulename>quiz</modulename><title>Toets</title><directory>activities/quiz_11</directory></activity>'
				. '<activity><moduleid>12</moduleid><modulename>resource</modulename><directory>activities/resource_12</directory></activity>'
				. '<activity><moduleid> </moduleid><modulename>url</modulename></activity>'
				. '<activity><moduleid>13</moduleid><sectionid>2</sectionid><title>Zonder type</title></activity>'
				. '<activity><moduleid>14</moduleid><modulename>forum</modulename><title>Forum</title></activity>'
				. '</activities><sections>'
				. '<section><sectionid>1</sectionid><title>Week 1</title></section>'
				. '<section><sectionid>2</sectionid></section>'
				. '<section><title>Geen id</title></section>'
				. '</sections></contents></information></moodle_backup>',
			'empty' => '<moodle_backup/>',
			'broken' => '<moodle_backup><unclosed></moodle_backup>',
			'missing' => null,
		];
		$actual = [];
		foreach ($manifests as $name => $xml) {
			$actual['manifest ' . $name] = $this->parse(xml: $xml);
		}

		$questions = '<question_categories><question_category><questions>'
			. '<question type="multichoice"><name>Hoofdstad</name><questiontext>Welke stad?</questiontext><defaultgrade>2</defaultgrade><single>true</single>'
			. '<answer fraction="100"><text> Amsterdam </text></answer><answer fraction="0"><text>Utrecht</text></answer></question>'
			. '<question type="multichoice"><name>Meer</name><single>false</single><answer fraction="50"><text>A</text></answer><answer fraction="50"><text>B</text></answer><answer fraction="-50"><text>C</text></answer></question>'
			. '<question type="shortanswer"><questiontext>Kort</questiontext><answer fraction="50"><text>half</text></answer><answer fraction="100"><text>goed</text></answer></question>'
			. '<question type="essay"><name>Opstel</name><defaultgrade>10</defaultgrade></question>'
			. '<question type="matching"><name>Koppel</name></question>'
			. '<question type="shortanswer"><name>Geen goed antwoord</name><answer fraction="90"><text>bijna</text></answer></question>'
			. '</questions></question_category></question_categories>';
		$actual['questions'] = $this->map(xml: $questions);

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testImportMatchesGolden()

	/**
	 * The parsed manifest, or the refusal.
	 *
	 * @param string|null $xml The moodle_backup.xml text, null for none.
	 *
	 * @return array<string,mixed>
	 */
	private function parse(?string $xml): array {
		$dir = sys_get_temp_dir() . '/learniq_mbz_golden_' . bin2hex(random_bytes(6));
		mkdir($dir, 0700, true);
		if ($xml !== null) {
			file_put_contents($dir . '/moodle_backup.xml', $xml);
		}

		try {
			return (new MoodleBackupParser())->parseManifest($dir);
		} catch (RuntimeException $e) {
			return ['refused' => str_replace($dir, '<dir>', $e->getMessage())];
		} finally {
			if ($xml !== null) {
				unlink($dir . '/moodle_backup.xml');
			}

			rmdir($dir);
		}
	}//end parse()

	/**
	 * The mapped questions.
	 *
	 * @param string $xml The questions.xml text.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function map(string $xml): array {
		$path = sys_get_temp_dir() . '/learniq_mbz_questions_' . bin2hex(random_bytes(6)) . '.xml';
		file_put_contents($path, $xml);
		try {
			return (new MoodleQuizQuestionMapper())->mapQuestions($path, 'bank-1', 'tenant-1');
		} finally {
			unlink($path);
		}
	}//end map()
}//end class
