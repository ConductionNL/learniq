<?php

/**
 * Tests for the xAPI document store: State and Agent Profile documents.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Exception\XapiRequestException;
use OCA\Learniq\Service\XapiDocumentCodec;
use OCA\Learniq\Service\XapiDocumentStore;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\XapiDocumentsInMemory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Round trips, merge, delete, listing and learner isolation.
 */
class XapiDocumentStoreTest extends TestCase {
	use XapiDocumentsInMemory;

	/**
	 * An activity IRI.
	 *
	 * @var string
	 */
	private const ACTIVITY = 'https://school.example/apps/learniq/lessons/lesson-1';

	/**
	 * A registration.
	 *
	 * @var string
	 */
	private const REGISTRATION = '0f8e7a2c-1b3d-4c5e-9f60-718293a4b5c6';

	/**
	 * The rows.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $rows;

	/**
	 * The store under test.
	 *
	 * @var XapiDocumentStore
	 */
	private XapiDocumentStore $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = new RegisterFaithfulStore();
		$config     = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('tenant-a');
		$this->store = new XapiDocumentStore(objectService: $this->xapiObjectService(store: $this->rows), config: $config, codec: new XapiDocumentCodec());
	}//end setUp()

	/**
	 * A state key for a learner.
	 *
	 * @param string $stateId      The stateId.
	 * @param string $actorId      The learner.
	 * @param string $registration The registration.
	 *
	 * @return array<string, string>
	 */
	private function stateKey(string $stateId, string $actorId = 'pupil1', string $registration = self::REGISTRATION): array {
		return $this->store->key(kind: XapiDocumentStore::KIND_STATE, actorId: $actorId, activityId: self::ACTIVITY, registration: $registration, documentId: $stateId);
	}//end stateKey()

	/**
	 * A PUT document reads back byte for byte, with its content type and the SHA-1 ETag.
	 *
	 * @return void
	 */
	public function testPutThenGetRoundTrips(): void {
		$etag = $this->store->put(key: $this->stateKey('bookmark'), contents: '{"page":3}', contentType: 'application/json', context: ['agent' => ['account' => ['name' => 'pupil1']], 'lessonId' => 'lesson-1']);

		$doc = $this->store->get(key: $this->stateKey('bookmark'));
		self::assertNotNull($doc);
		self::assertSame('{"page":3}', $doc['contents']);
		self::assertSame('application/json', $doc['contentType']);
		self::assertSame('"' . sha1('{"page":3}') . '"', $etag);
		self::assertSame($etag, $doc['etag']);

		$row = $this->rows->rows['xapi-document'][0];
		self::assertSame('pupil1', $row['verified_actor_id']);
		self::assertSame('tenant-a', $row['tenant_id']);
		self::assertSame('lesson-1', $row['lessonId']);
		self::assertSame('state', $row['kind']);
	}//end testPutThenGetRoundTrips()

	/**
	 * A binary document survives storage as base64.
	 *
	 * @return void
	 */
	public function testBinaryDocumentRoundTrips(): void {
		$bytes = "\x89PNG\r\n\x1a\n\xff\xfe";
		$this->store->put(key: $this->stateKey('image'), contents: $bytes, contentType: 'image/png', context: []);

		self::assertSame('base64', $this->rows->rows['xapi-document'][0]['contentEncoding']);
		self::assertSame($bytes, $this->store->get(key: $this->stateKey('image'))['contents'] ?? null);
	}//end testBinaryDocumentRoundTrips()

	/**
	 * POST creates a missing document, then merges top-level members into it.
	 *
	 * @return void
	 */
	public function testMergeCreatesThenMerges(): void {
		$this->store->merge(key: $this->stateKey('progress'), contents: '{"a":1,"b":{"x":1}}', context: []);
		$this->store->merge(key: $this->stateKey('progress'), contents: '{"b":{"y":2},"c":3}', context: []);

		$doc = $this->store->get(key: $this->stateKey('progress'));
		self::assertSame(['a' => 1, 'b' => ['y' => 2], 'c' => 3], json_decode((string)($doc['contents'] ?? ''), true));
		self::assertSame('application/json', $doc['contentType'] ?? null);
		self::assertCount(1, $this->rows->rows['xapi-document'], 'one document, merged in place');
	}//end testMergeCreatesThenMerges()

	/**
	 * POST refuses a body or a stored document that is not a JSON object.
	 *
	 * @return void
	 */
	public function testMergeRefusesNonObjects(): void {
		$refused = 0;
		try {
			$this->store->merge(key: $this->stateKey('progress'), contents: '[1,2]', context: []);
		} catch (XapiRequestException $e) {
			$refused++;
		}

		$this->store->put(key: $this->stateKey('text'), contents: 'plain text', contentType: 'text/plain', context: []);
		try {
			$this->store->merge(key: $this->stateKey('text'), contents: '{"a":1}', context: []);
		} catch (XapiRequestException $e) {
			$refused++;
		}

		self::assertSame(2, $refused);
		self::assertSame('plain text', $this->store->get(key: $this->stateKey('text'))['contents'] ?? null, 'the stored document is untouched');
	}//end testMergeRefusesNonObjects()

	/**
	 * A document is keyed by learner and registration: nobody else reads it.
	 *
	 * @return void
	 */
	public function testDocumentsAreIsolatedPerLearnerAndRegistration(): void {
		$this->store->put(key: $this->stateKey('bookmark'), contents: 'mine', contentType: 'text/plain', context: []);

		self::assertNull($this->store->get(key: $this->stateKey('bookmark', 'pupil2')), 'another learner');
		self::assertNull($this->store->get(key: $this->stateKey('bookmark', 'pupil1', '')), 'no registration');
		self::assertNull($this->store->get(key: $this->stateKey('bookmark', 'pupil1', '11111111-2222-4333-8444-555555555555')), 'another registration');
		self::assertSame([], $this->store->listIds(key: $this->stateKey('', 'pupil2'), since: ''));
	}//end testDocumentsAreIsolatedPerLearnerAndRegistration()

	/**
	 * The stateIds list honours `since`, and a bulk delete removes only its own key.
	 *
	 * @return void
	 */
	public function testListSinceAndDeleteAll(): void {
		$this->store->put(key: $this->stateKey('b'), contents: '1', contentType: 'text/plain', context: []);
		$this->store->put(key: $this->stateKey('a'), contents: '2', contentType: 'text/plain', context: []);
		$this->store->put(key: $this->stateKey('other', 'pupil1', ''), contents: '3', contentType: 'text/plain', context: []);

		self::assertSame(['a', 'b'], $this->store->listIds(key: $this->stateKey(''), since: ''));
		self::assertSame([], $this->store->listIds(key: $this->stateKey(''), since: '2999-01-01T00:00:00Z'));

		$this->store->deleteAll(key: $this->stateKey(''));
		self::assertSame([], $this->store->listIds(key: $this->stateKey(''), since: ''));
		self::assertSame(['other'], $this->store->listIds(key: $this->stateKey('', 'pupil1', ''), since: ''), 'the unregistered state survives');
	}//end testListSinceAndDeleteAll()

	/**
	 * Delete removes one document and is quiet about a missing one.
	 *
	 * @return void
	 */
	public function testDelete(): void {
		$this->store->put(key: $this->stateKey('bookmark'), contents: 'x', contentType: 'text/plain', context: []);
		$this->store->delete(key: $this->stateKey('bookmark'));
		$this->store->delete(key: $this->stateKey('never-there'));

		self::assertNull($this->store->get(key: $this->stateKey('bookmark')));
		self::assertSame([], $this->rows->rows['xapi-document']);
	}//end testDelete()

	/**
	 * State and agent profile documents with the same id do not collide.
	 *
	 * @return void
	 */
	public function testKindsDoNotCollide(): void {
		$profile = $this->store->key(kind: XapiDocumentStore::KIND_AGENT_PROFILE, actorId: 'pupil1', activityId: '', registration: '', documentId: 'prefs');
		$this->store->put(key: $profile, contents: 'profile', contentType: 'text/plain', context: []);
		$this->store->put(key: $this->stateKey('prefs'), contents: 'state', contentType: 'text/plain', context: []);

		self::assertSame('profile', $this->store->get(key: $profile)['contents'] ?? null);
		self::assertSame(['prefs'], $this->store->listIds(key: array_replace($profile, ['documentId' => '']), since: ''));
	}//end testKindsDoNotCollide()
}//end class
