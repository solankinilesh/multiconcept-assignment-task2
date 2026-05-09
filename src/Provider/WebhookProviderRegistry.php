<?php

declare(strict_types=1);

namespace App\Provider;

use App\Exception\UnknownProviderException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves a URL slug like `stripe` or `mailgun` to the matching provider service.
 *
 * Tagged-iterator injection means new providers self-register: implement the interface,
 * the autoconfigure tag picks it up, and this registry sees it on the next container
 * compile. No central list to maintain.
 */
final class WebhookProviderRegistry
{
    /** @var array<string, WebhookProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<WebhookProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.webhook_provider')]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    /**
     * @throws UnknownProviderException
     */
    public function get(string $name): WebhookProviderInterface
    {
        return $this->providers[$name] ?? throw UnknownProviderException::forName($name);
    }

    /**
     * @return list<string>
     */
    public function getRegisteredNames(): array
    {
        return array_keys($this->providers);
    }
}
