<?php

/**
 * Unit tests for LearningRecordShareVerifyController.
 *
 * Verifies: an active + unexpired share resolves the shared bundle; a
 * revoked share denies; an expired-but-still-`active`-lifecycle share
 * denies (mirrors Credential.isExpired's no-scheduled-transition shape);
 * a signature-invalid bundle denies.
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
 * @spec openspec/changes/portable-learning-record/tasks.md#task-3-3
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\LearningRecordShareVerifyController;
use OCA\Scholiq\Service\LearningRecordExportSigningService;
use OCA\Scholiq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearningRecordShareVerifyController::verify().
 */
class LearningRecordShareVerifyControllerTest extends TestCase
{

    /**
     * ObjectService mock, dispatching find() by schema.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * LearningRecordExportSigningService mock.
     *
     * @var LearningRecordExportSigningService&MockObject
     */
    private LearningRecordExportSigningService&MockObject $signingService;

    /**
     * Per-schema fixture rows, keyed by "schema:id".
     *
     * @var array<string,array<string,mixed>>
     */
    private array $objectsBySchemaId = [];

    /**
     * Raw bundle JSON served by the IRootFolder mock for the bundle file.
     *
     * @var string
     */
    private string $bundleFileContent = '{}';

    /**
     * The controller under test.
     *
     * @var LearningRecordShareVerifyController
     */
    private LearningRecordShareVerifyController $controller;

    /**
     * Set up the controller under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectsBySchemaId = [];
        $this->bundleFileContent = '{}';

        $this->objectService = $this->createMock(ObjectService::class);
        // OpenRegister's find() is find($id, $_extend, $files, $register, $schema, ...)
        // and returns ?ObjectEntity. willReturnCallback() hands the closure the
        // mock's arguments POSITIONALLY, so the closure must mirror that order.
        // ObjectEntity's getters come from Entity::__call, so a real instance is
        // required — a mock cannot configure them.
        $this->objectService->method('find')->willReturnCallback(
            function (int | string $id, ?array $_extend=[], bool $files=false, $register=null, $schema=null): ?ObjectEntity {
                $data = $this->objectsBySchemaId[$schema.':'.$id] ?? null;
                if ($data === null) {
                    return null;
                }

                return OrEntityFactory::make($data, (string) $schema, 'scholiq', (string) $id);
            }
        );

        $this->signingService = $this->createMock(LearningRecordExportSigningService::class);

        /** @var File&MockObject $file */
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturnCallback(fn () => $this->bundleFileContent);

        /** @var Folder&MockObject $folder */
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturn($file);

        /** @var IRootFolder&MockObject $rootFolder */
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($folder);

        $this->controller = new LearningRecordShareVerifyController(
            request: $this->createMock(IRequest::class),
            objectService: $this->objectService,
            signingService: $this->signingService,
            rootFolder: $rootFolder,
        );
    }//end setUp()

    /**
     * An active, unexpired share with a valid signature resolves the shared bundle.
     *
     * @return void
     */
    public function testActiveUnexpiredShareResolvesBundle(): void
    {
        $this->objectsBySchemaId['learning-record-share:share-1'] = [
            'id' => 'share-1',
            'lifecycle' => 'active',
            'isExpired' => false,
            'learningRecordExportId' => 'export-1',
            'accessCount' => 0,
        ];
        $this->objectsBySchemaId['learning-record-export:export-1'] = [
            'id' => 'export-1',
            'learnerId' => 'anna',
            'bundleRef' => '/Scholiq/tenant-1/learning-record-exports/export-1.json',
            'bundleSignature' => 'header..signature',
            'tenant_id' => 'tenant-1',
        ];
        $this->bundleFileContent = (string) json_encode(['scholiqNative' => ['credentials' => []]]);

        $this->signingService->method('verify')->willReturn(true);

        $this->objectService->expects($this->once())->method('saveObject');

        $response = $this->controller->verify('share-1');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertTrue($response->getData()['valid']);
        self::assertArrayHasKey('bundle', $response->getData());
    }//end testActiveUnexpiredShareResolvesBundle()

    /**
     * A revoked share is denied without partial data.
     *
     * @return void
     */
    public function testRevokedShareIsDenied(): void
    {
        $this->objectsBySchemaId['learning-record-share:share-2'] = [
            'id' => 'share-2',
            'lifecycle' => 'revoked',
            'isExpired' => false,
        ];

        $this->objectService->expects($this->never())->method('saveObject');

        $response = $this->controller->verify('share-2');

        self::assertFalse($response->getData()['valid']);
        self::assertSame('revoked', $response->getData()['reason']);
        self::assertArrayNotHasKey('bundle', $response->getData());
    }//end testRevokedShareIsDenied()

    /**
     * An expired share (isExpired: true) is denied even though its
     * lifecycle is still `active` — mirrors Credential.isExpired's
     * no-scheduled-transition shape.
     *
     * @return void
     */
    public function testExpiredShareIsDeniedDespiteActiveLifecycle(): void
    {
        $this->objectsBySchemaId['learning-record-share:share-3'] = [
            'id' => 'share-3',
            'lifecycle' => 'active',
            'isExpired' => true,
        ];

        $response = $this->controller->verify('share-3');

        self::assertFalse($response->getData()['valid']);
        self::assertSame('expired', $response->getData()['reason']);
    }//end testExpiredShareIsDeniedDespiteActiveLifecycle()

    /**
     * A share whose export's bundleSignature fails verification is denied.
     *
     * @return void
     */
    public function testSignatureInvalidShareIsDenied(): void
    {
        $this->objectsBySchemaId['learning-record-share:share-4'] = [
            'id' => 'share-4',
            'lifecycle' => 'active',
            'isExpired' => false,
            'learningRecordExportId' => 'export-4',
        ];
        $this->objectsBySchemaId['learning-record-export:export-4'] = [
            'id' => 'export-4',
            'learnerId' => 'anna',
            'bundleRef' => '/Scholiq/tenant-1/learning-record-exports/export-4.json',
            'bundleSignature' => 'header..tampered',
            'tenant_id' => 'tenant-1',
        ];
        $this->bundleFileContent = (string) json_encode(['scholiqNative' => []]);

        $this->signingService->method('verify')->willReturn(false);

        $this->objectService->expects($this->never())->method('saveObject');

        $response = $this->controller->verify('share-4');

        self::assertFalse($response->getData()['valid']);
        self::assertSame('signature_invalid', $response->getData()['reason']);
    }//end testSignatureInvalidShareIsDenied()

    /**
     * An unknown share id is denied with `not_found`.
     *
     * @return void
     */
    public function testUnknownShareIsDenied(): void
    {
        $response = $this->controller->verify('does-not-exist');

        self::assertFalse($response->getData()['valid']);
        self::assertSame('not_found', $response->getData()['reason']);
        self::assertSame(404, $response->getStatus());
    }//end testUnknownShareIsDenied()

    /**
     * A THROWING ObjectService is denied, not propagated.
     *
     * `find()` does not only return null for an id that does not resolve — it
     * THROWS, both for an unknown object and, via ObjectService::setSchema(),
     * for a schema the register has never imported. verify() is a
     * `#[PublicPage]`, so an escaping exception makes Nextcloud render
     * printExceptionErrorPage() and the caller receives an HTML error page
     * where it asked for JSON; the verify view then dies on
     * `SyntaxError: Unexpected token '<'`.
     *
     * The other tests on this class cannot reach that path: the shared
     * ObjectService mock RETURNS null for an unknown id, so `not_found` is
     * produced by the `$obj === null` branch and the `catch` block is never
     * entered. This test installs a mock that throws instead, which is the
     * only way the fail-closed behaviour is actually exercised.
     *
     * @return void
     */
    public function testThrowingObjectServiceIsDeniedRatherThanPropagated(): void
    {
        /** @var ObjectService&MockObject $throwingObjectService */
        $throwingObjectService = $this->createMock(ObjectService::class);
        $throwingObjectService->method('find')->willThrowException(
            new \OCP\AppFramework\Db\DoesNotExistException('schema not imported')
        );

        $controller = new LearningRecordShareVerifyController(
            request: $this->createMock(IRequest::class),
            objectService: $throwingObjectService,
            signingService: $this->signingService,
            rootFolder: $this->createMock(IRootFolder::class),
        );

        $response = $controller->verify('any-id');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertFalse($response->getData()['valid']);
        self::assertSame('not_found', $response->getData()['reason']);
        self::assertSame(404, $response->getStatus());
    }//end testThrowingObjectServiceIsDeniedRatherThanPropagated()
}//end class
