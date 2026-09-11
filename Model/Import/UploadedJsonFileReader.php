<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Import;

use Magento\Framework\App\RequestInterface;

/**
 * Shared "read an uploaded JSON file from this POST request" step for
 * Controller\Adminhtml\{Campaign,Segment}\Import - one place for the getFiles()/decode/validate
 * dance both controllers otherwise duplicated identically.
 *
 * getFiles() genuinely exists at runtime (Laminas\Http\Request::getFiles(), which
 * Magento\Framework\App\Request\Http ultimately extends) but isn't declared on
 * Magento\Framework\App\RequestInterface itself - the instanceof check below is for static
 * analysis, not a real runtime possibility this request is some other implementation (every
 * admin controller's $this->getRequest() is always the real Http request).
 */
class UploadedJsonFileReader
{
    /**
     * @return array<string, mixed>
     * @throws \InvalidArgumentException no file was posted, the upload itself failed, or its
     *   contents aren't valid JSON.
     */
    public function read(RequestInterface $request, string $fieldName): array
    {
        if (!$request instanceof \Magento\Framework\App\Request\Http) {
            throw new \InvalidArgumentException('Choose a file to import.');
        }

        $file = $request->getFiles($fieldName);
        $tmpName = is_array($file) ? ($file['tmp_name'] ?? null) : null;
        $error = is_array($file) ? ($file['error'] ?? null) : null;

        if (!is_string($tmpName) || $tmpName === '' || !is_int($error) || $error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Choose a file to import.');
        }

        $contents = file_get_contents($tmpName);
        $decoded = $contents !== false ? json_decode($contents, true) : null;

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('That file is not valid JSON.');
        }

        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }

        return $stringKeyed;
    }
}
