<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdminActionLog;

use Magento\User\Model\User;

/**
 * getUserId() only exists as a magic getter (backed by AbstractModel's __call()/getData()) - see
 * BackendAuthSessionTestDouble's own docblock for why that can't be stubbed directly anymore.
 * getUserName() is a real declared method on User, but overridden here too so this double never
 * needs a live DB-backed AbstractModel underneath it.
 */
class UserTestDouble extends User
{
    private ?int $testUserId = null;
    private ?string $testUserName = null;

    public function __construct()
    {
        // Deliberately skips parent::__construct() - this double only ever needs the two
        // getters below.
    }

    public function setTestUserId(?int $userId): self
    {
        $this->testUserId = $userId;
        return $this;
    }

    public function setTestUserName(?string $userName): self
    {
        $this->testUserName = $userName;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->testUserId;
    }

    public function getUserName(): ?string
    {
        return $this->testUserName;
    }
}
