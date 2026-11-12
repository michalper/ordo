<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Gdpr;

use Ordo\Automation\Model\ConsentChannel;

/**
 * One customer's consent state across every channel, as real named getters instead of a plain
 * array{...} shape — only PHPStan protected the array form, this is protected by the language
 * itself (a typo'd key throws at the call site, not silently returns null/a missing offset).
 *
 * Still implements IteratorAggregate over channel => consented, so
 * view/adminhtml/templates/gdpr/index.phtml can keep a plain `foreach ($consent as $channel =>
 * $consented)` and render a new channel automatically the moment one is added here, without
 * hardcoding every getter into the template.
 */
/**
 * @implements \IteratorAggregate<string, bool>
 */
class ConsentStates implements \IteratorAggregate
{
    public function __construct(
        private readonly bool $email,
        private readonly bool $sms,
        private readonly bool $push,
        private readonly bool $whatsapp,
        private readonly bool $rcs,
        private readonly bool $ads
    ) {
    }

    public function isEmailConsented(): bool
    {
        return $this->email;
    }

    public function isSmsConsented(): bool
    {
        return $this->sms;
    }

    public function isPushConsented(): bool
    {
        return $this->push;
    }

    public function isWhatsAppConsented(): bool
    {
        return $this->whatsapp;
    }

    public function isRcsConsented(): bool
    {
        return $this->rcs;
    }

    public function isAdsConsented(): bool
    {
        return $this->ads;
    }

    /**
     * @return \ArrayIterator<string, bool>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator([
            ConsentChannel::Email->value => $this->email,
            ConsentChannel::Sms->value => $this->sms,
            ConsentChannel::Push->value => $this->push,
            ConsentChannel::WhatsApp->value => $this->whatsapp,
            ConsentChannel::Rcs->value => $this->rcs,
            ConsentChannel::Ads->value => $this->ads,
        ]);
    }
}
