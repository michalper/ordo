<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Fetches a public product feed URL (Controller\ProductFeed\MetaCatalog / Index) directly via an
 * unauthenticated HTTP GET, the same reasoning as AdminExportDownloadTestHelper: a response whose
 * Content-Type has no browser-native inline viewer (text/csv, unlike the Google feed's
 * application/xml, which Chrome does render inline as a DOM tree) gets handed off to the OS/
 * browser's own download handling instead of being navigated to, so seeInPageSource after
 * amOnPage would still see the PREVIOUS page's DOM. No cookie needed - this endpoint is
 * deliberately public (see AbstractFeedAction's own doc).
 */
class PublicFeedDownloadTestHelper extends Helper
{
    public function fetchFeedBody(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Could not reach feed endpoint at {$url}: {$error}");
        }

        curl_close($ch);

        return (string) $body;
    }
}
