<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Cron;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog as CronRunLogResource;

/**
 * One persisted row per CronRunLogger::logFailure()/logSummary() call - closes the "did today's
 * escalation cron even run" ROADMAP.md gap, where CronRunLogger only ever wrote to var/log
 * (Psr\Log\LoggerInterface), invisible without log-tailing. Plain data holder; CronRunLogger
 * itself is still the only thing that writes these.
 */
class CronRunLog extends AbstractModel
{
    public const string LEVEL_SUMMARY = 'summary';
    public const string LEVEL_FAILURE = 'failure';

    protected function _construct(): void
    {
        $this->_init(CronRunLogResource::class);
    }

    public function getLevel(): string
    {
        return (string) $this->getData('level');
    }

    public function setLevel(string $level): self
    {
        $this->setData('level', $level);
        return $this;
    }

    public function getMessage(): string
    {
        return (string) $this->getData('message');
    }

    public function setMessage(string $message): self
    {
        $this->setData('message', $message);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData('created_at');
        return $value === null ? null : (string) $value;
    }
}
