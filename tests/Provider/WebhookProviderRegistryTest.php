<?php

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Exception\UnknownProviderException;
use App\Provider\MailgunProvider;
use App\Provider\StripeProvider;
use App\Provider\WebhookProviderRegistry;
use App\Signature\StripeSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookProviderRegistryTest extends TestCase
{
    #[Test]
    public function returns_a_provider_by_its_registered_name(): void
    {
        $registry = $this->buildRegistry();

        self::assertInstanceOf(StripeProvider::class, $registry->get('stripe'));
        self::assertInstanceOf(MailgunProvider::class, $registry->get('mailgun'));
    }

    #[Test]
    public function throws_unknown_provider_for_an_unrecognised_name(): void
    {
        $registry = $this->buildRegistry();

        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('paypal');
        $registry->get('paypal');
    }

    #[Test]
    public function lists_all_registered_provider_names(): void
    {
        $registry = $this->buildRegistry();

        self::assertEqualsCanonicalizing(['stripe', 'mailgun'], $registry->getRegisteredNames());
    }

    // Container wiring (autoconfigure tag → tagged iterator) is exercised end-to-end by
    // the controller tests in Phase 4: those resolve the registry via DI through the
    // controller, so any breakage in tagging shows up as 404s in functional tests.

    private function buildRegistry(): WebhookProviderRegistry
    {
        return new WebhookProviderRegistry([
            new StripeProvider(new StripeSignatureVerifier(), 'whsec_unit_test'),
            new MailgunProvider('whsec_unit_test_mg'),
        ]);
    }
}
