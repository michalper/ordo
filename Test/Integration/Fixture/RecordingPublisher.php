<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Records (topic, body) instead of publishing to a real queue - the one collaborator a test
 * swaps, same "override just the risky collaborator" pattern every other Test/Integration file
 * already uses.
 */
class RecordingPublisher implements PublisherInterface
{
    /** @var array<int, array{topic: string, body: string}> */
    public array $published = [];

    public function publish($topicName, $data)
    {
        $this->published[] = ['topic' => $topicName, 'body' => (string) $data];
    }
}
