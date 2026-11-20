<?php

namespace App\Providers;

use App\Integration\Gib\DocumentVoucherBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the default voucher builder in the static registry.
 * Intentionally side-effect-free beyond that single registration.
 */
final class IntegrationVoucherProvider extends ServiceProvider
{
    public function register(): void
    {
        DocumentVoucherBuilder::bootstrap();
    }
}
