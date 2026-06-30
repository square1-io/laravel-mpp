<?php

namespace Square1\Mpp\Attributes;

use Attribute;

/**
 * Marks a controller action (or an invokable/whole controller) as requiring an
 * MPP payment. Read at request time by the package middleware.
 *
 *   #[RequiresPayment(amount: '0.50', currency: 'USD')]            // once-off
 *   #[RequiresPayment(amount: '5.00', currency: 'USD', grants: 10, scope: 'report.basic')] // metered bundle
 *
 * `grants > 1` issues a prepaid session (one charge, N accesses); `grants = 1`
 * is a once-off per-request charge.
 *
 * Offer several native settlement rails at once with `methods` (ordered,
 * primary first); the first entry is treated as the primary:
 *
 *   #[RequiresPayment(amount: '0.50', methods: ['stripe', 'acme'])]
 *
 * Tempo uses the separate mppx dialect and cannot be co-offered with native
 * rails; use `method: 'tempo'` when a route should emit a Tempo challenge.
 *
 * Leave `methods` null to use the package default offered set (`mpp.accept`, or
 * `mpp.default_method` when `mpp.accept` is unset). `method` still sets a
 * single primary method.
 *
 * `amount`, `currency` and `grants` are optional: omit them to inherit the
 * global `mpp.defaults` (e.g. `MPP_DEFAULT_AMOUNT`), overridable per attribute.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class RequiresPayment
{
    /**
     * @param  list<string>|null  $methods  ordered set of offered settlement methods (primary first)
     */
    public function __construct(
        public readonly string|float|null $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $grants = null,
        public readonly ?string $scope = null,
        public readonly ?string $method = null,
        public readonly ?array $methods = null,
    ) {}
}
