<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

/**
 * Generates a plaintext AI-agent API key and its sha256 hex digest - the same "generate,
 * persist only the hash, show the plaintext exactly once" model as any API token screen.
 * Console\Command\ApiKey\GenerateCommand is the only caller that ever sees the plaintext;
 * Model\AiAgent\ApiKeyAuthenticator only ever compares hashes.
 */
class ApiKeyGenerator
{
    private const string PREFIX = 'oaa_';

    /**
     * @return array{plaintext: string, hash: string}
     */
    public function generate(): array
    {
        $plaintext = self::PREFIX . bin2hex(random_bytes(32));

        return ['plaintext' => $plaintext, 'hash' => $this->hash($plaintext)];
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
