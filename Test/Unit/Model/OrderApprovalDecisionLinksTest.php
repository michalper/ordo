<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\OrderApprovalDecisionLinks;
use PHPUnit\Framework\TestCase;

class OrderApprovalDecisionLinksTest extends TestCase
{
    public function testApproveUrlRoundTrip(): void
    {
        $links = new OrderApprovalDecisionLinks();
        $links->setApproveUrl('https://example.com/approve');

        self::assertSame('https://example.com/approve', $links->getApproveUrl());
    }

    public function testRejectUrlRoundTrip(): void
    {
        $links = new OrderApprovalDecisionLinks();
        $links->setRejectUrl('https://example.com/reject');

        self::assertSame('https://example.com/reject', $links->getRejectUrl());
    }

    public function testGettersReturnEmptyStringWhenUnset(): void
    {
        $links = new OrderApprovalDecisionLinks();

        self::assertSame('', $links->getApproveUrl());
        self::assertSame('', $links->getRejectUrl());
    }
}
