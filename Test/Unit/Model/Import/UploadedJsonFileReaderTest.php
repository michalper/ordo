<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Import;

use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem\Driver\File;
use Ordo\Automation\Model\Import\UploadedJsonFileReader;
use PHPUnit\Framework\TestCase;

class UploadedJsonFileReaderTest extends TestCase
{
    private UploadedJsonFileReader $reader;

    protected function setUp(): void
    {
        $this->reader = new UploadedJsonFileReader(new File());
    }

    private function makeTmpFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ordo_import_test_');
        file_put_contents($path, $contents);

        return $path;
    }

    public function testReadDecodesAValidJsonUpload(): void
    {
        $path = $this->makeTmpFile('{"export_type":"ordo_segment","name":"VIPs"}');

        $request = $this->createStub(Http::class);
        $request->method('getFiles')->willReturn([
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
        ]);

        $result = $this->reader->read($request, 'import_file');

        self::assertSame(['export_type' => 'ordo_segment', 'name' => 'VIPs'], $result);

        unlink($path);
    }

    public function testReadRejectsAnUploadError(): void
    {
        $request = $this->createStub(Http::class);
        $request->method('getFiles')->willReturn([
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->reader->read($request, 'import_file');
    }

    public function testReadRejectsWhenNoFileWasSubmittedAtAll(): void
    {
        $request = $this->createStub(Http::class);
        $request->method('getFiles')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->reader->read($request, 'import_file');
    }

    public function testReadRejectsMalformedJson(): void
    {
        $path = $this->makeTmpFile('not json at all {');

        $request = $this->createStub(Http::class);
        $request->method('getFiles')->willReturn([
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That file is not valid JSON.');

        try {
            $this->reader->read($request, 'import_file');
        } finally {
            unlink($path);
        }
    }

    public function testReadRejectsATopLevelJsonArrayInsteadOfAnObject(): void
    {
        // json_decode of a top-level JSON array still passes is_array(), but every key is an
        // int, not a string - the string-keyed filter below must drop all of them, matching an
        // export payload's own shape (always a JSON object, never a bare list).
        $path = $this->makeTmpFile('[1,2,3]');

        $request = $this->createStub(Http::class);
        $request->method('getFiles')->willReturn([
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
        ]);

        $result = $this->reader->read($request, 'import_file');

        self::assertSame([], $result);

        unlink($path);
    }

    public function testReadRejectsWhenTheUploadedFileCannotActuallyBeRead(): void
    {
        // A tmp_name that passed the earlier "is a non-empty string" check but doesn't exist on
        // disk (the upload succeeded per PHP's own $_FILES bookkeeping, yet the file itself is
        // gone by the time this runs) - File::fileGetContents() throws FileSystemException here,
        // which must be treated the same as malformed JSON, not bubble up as an unhandled error.
        $request = $this->createStub(Http::class);
        $request->method('getFiles')->willReturn([
            'tmp_name' => sys_get_temp_dir() . '/ordo_import_test_does_not_exist_' . uniqid(),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That file is not valid JSON.');
        $this->reader->read($request, 'import_file');
    }

    public function testReadRejectsARequestThatIsNotTheRealHttpImplementation(): void
    {
        $request = $this->createStub(RequestInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->reader->read($request, 'import_file');
    }
}
