<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Magento\Framework\Mail\Template\TransportBuilder;

/**
 * Delegates every state-accumulating call to the real TransportBuilder (safe — they just set
 * private properties, no I/O), but overrides getTransport() so the actual template render and
 * mail send never happen.
 */
class RecordingTransportBuilder extends TransportBuilder
{
    /** @var array<string, mixed> */
    public array $calls = [];

    public ?SpyTransport $recordedTransport = null;

    public function setTemplateIdentifier($templateIdentifier)
    {
        $this->calls['templateIdentifier'] = $templateIdentifier;
        return parent::setTemplateIdentifier($templateIdentifier);
    }

    public function addTo($address, $name = null)
    {
        $this->calls['to'][] = [$address, $name];
        return parent::addTo($address, $name);
    }

    public function setTemplateVars($templateVars)
    {
        $this->calls['templateVars'] = $templateVars;
        return parent::setTemplateVars($templateVars);
    }

    public function getTransport()
    {
        $this->recordedTransport = new SpyTransport();
        return $this->recordedTransport;
    }
}
