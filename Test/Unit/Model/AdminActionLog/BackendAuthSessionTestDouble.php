<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdminActionLog;

use Magento\Backend\Model\Auth\Session as BackendAuthSession;
use Magento\User\Model\User;

/**
 * getUser() only exists as a magic @method docblock on Session (backed by __call()/getData()) -
 * see Test\Unit\QuoteTestDouble's own docblock for why PHPUnit's MockBuilder can no longer stub
 * that directly (addMethods() was removed in PHPUnit 12). This gives it a real, declared,
 * therefore-mockable-via-onlyMethods() override instead.
 */
class BackendAuthSessionTestDouble extends BackendAuthSession
{
    private ?User $testUser = null;

    public function __construct()
    {
        // Deliberately skips parent::__construct() - this double only ever needs getUser().
    }

    public function setTestUser(?User $user): self
    {
        $this->testUser = $user;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->testUser;
    }
}
