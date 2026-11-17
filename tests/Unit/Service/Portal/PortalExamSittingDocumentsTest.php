<?php

/**
 * An assessor reads a sitting and its documents only while assigned to it.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalExamSittingDocuments;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Ruud is assigned to the 9.00 sitting; the 11.00 sitting is another assessor's.
 *
 * @covers \OCA\Learniq\Service\Portal\PortalExamSittingDocuments
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 */
class PortalExamSittingDocumentsTest extends TestCase {

	private const RUUD = 'ee010008-0000-4000-8000-000000000701';

	private const SANNE = 'ee010008-0000-4000-8000-000000000702';

	private const DAAN = 'ee010008-0000-4000-8000-000000000501';

	private const NINE = 'ee010008-0000-4000-8000-000000000601';

	private const ELEVEN = 'ee010008-0000-4000-8000-000000000602';

	private const MODEL = 'ee010008-0000-4000-8000-000000000611';

	private const RULES = 'ee010008-0000-4000-8000-000000000612';

	private const OTHER = 'ee010008-0000-4000-8000-000000000613';

	private const TENANT = 'ee010008-0000-4000-8000-000000000901';

	private RegisterFaithfulStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'exam-assessor-assignment' => [
				['id' => 'as-1', 'examSittingId' => self::NINE, 'externalAssessorId' => self::RUUD, 'learnerRef' => self::DAAN, 'role' => 'first', 'lifecycle' => 'assigned', 'tenant_id' => self::TENANT],
				['id' => 'as-2', 'examSittingId' => self::ELEVEN, 'externalAssessorId' => self::SANNE, 'learnerRef' => self::DAAN, 'role' => 'first', 'lifecycle' => 'assigned', 'tenant_id' => self::TENANT],
				['id' => 'as-3', 'examSittingId' => self::ELEVEN, 'externalAssessorId' => self::RUUD, 'learnerRef' => self::DAAN, 'role' => 'second', 'lifecycle' => 'withdrawn', 'tenant_id' => self::TENANT],
			],
			'exam-sitting' => [
				['id' => self::NINE, 'startsAt' => '2026-11-16T09:00:00+01:00', 'endsAt' => '2026-11-16T10:30:00+01:00', 'roomIds' => ['p104'], 'documentIds' => [self::RULES, self::MODEL, 7], 'lifecycle' => 'planned', 'headcount' => 3, 'tenant_id' => self::TENANT],
				['id' => self::ELEVEN, 'startsAt' => '2026-11-16T11:00:00+01:00', 'endsAt' => '2026-11-16T12:30:00+01:00', 'roomIds' => ['p105'], 'documentIds' => [self::OTHER], 'lifecycle' => 'planned', 'headcount' => 1, 'tenant_id' => self::TENANT],
			],
			'material' => [
				['id' => self::MODEL, 'title' => 'Beoordelingsmodel installeren', 'kind' => 'document', 'fileRef' => 'or:1001', 'tenant_id' => self::TENANT],
				['id' => self::RULES, 'title' => 'Examenreglement', 'kind' => 'document', 'url' => 'https://school.example/reglement.pdf', 'tenant_id' => self::TENANT],
				['id' => self::OTHER, 'title' => 'Beoordelingsmodel onderhoud', 'kind' => 'document', 'fileRef' => 'or:1002', 'tenant_id' => self::TENANT],
			],
		];
	}

	private function service(): PortalExamSittingDocuments {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return new PortalExamSittingDocuments(objectService: $objects, logger: new NullLogger());
	}

	public function testHisSittingCarriesItsDocumentsInTheSittingsOrder(): void {
		$outcome = $this->service()->sitting(assessorRef: self::RUUD, sittingId: self::NINE);

		self::assertSame(200, $outcome->status);
		self::assertSame('2026-11-16T09:00:00+01:00', $outcome->body['startsAt']);
		self::assertSame(['p104'], $outcome->body['roomIds']);
		self::assertArrayNotHasKey('headcount', $outcome->body);
		self::assertSame(
			[
				['id' => self::RULES, 'title' => 'Examenreglement', 'kind' => 'document', 'fileRef' => null, 'url' => 'https://school.example/reglement.pdf'],
				['id' => self::MODEL, 'title' => 'Beoordelingsmodel installeren', 'kind' => 'document', 'fileRef' => 'or:1001', 'url' => null],
			],
			$outcome->body['documents']
		);
	}

	public function testASittingHeIsNotAssignedToResolvesNothingNotEvenItsDocuments(): void {
		$refusals = [
			$this->service()->sitting(assessorRef: self::RUUD, sittingId: self::ELEVEN),
			$this->service()->sitting(assessorRef: self::RUUD, sittingId: 'ee010008-0000-4000-8000-000000000699'),
		];

		foreach ($refusals as $refusal) {
			self::assertSame(403, $refusal->status);
			self::assertSame(['error' => 'not_assigned'], $refusal->body);
		}

		foreach ($this->store->reads as $read) {
			self::assertNotSame('material', $read['config']['filters']['schema'], 'no document is read for a refused sitting');
			self::assertNotSame('exam-sitting', $read['config']['filters']['schema'], 'not even the sitting');
		}
	}

	public function testAnIncompleteRequestAndAFailedRead(): void {
		self::assertSame(422, $this->service()->sitting(assessorRef: '', sittingId: self::NINE)->status);
		self::assertSame(422, $this->service()->sitting(assessorRef: self::RUUD, sittingId: '')->status);

		$this->store->failReads = 'database gone';
		self::assertSame(['error' => 'downstream_error'], $this->service()->sitting(assessorRef: self::RUUD, sittingId: self::NINE)->body);
	}
}
