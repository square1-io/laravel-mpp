<?php

namespace Square1\Mpp\Attributes;

use Attribute;

/**
 * Marks a controller action as a route that requires an MPP payment. The
 * attribute also marks an invokable class or a whole controller. The middleware
 * of the package reads it at request time.
 *
 *   #[RequiresPayment(amount: '0.50', currency: 'USD')]            // once-off
 *   #[RequiresPayment(amount: '5.00', currency: 'USD', grants: 10, scope: 'report.basic')] // metered bundle
 *
 * `grants > 1` issues a prepaid session, which is one charge for N accesses.
 * `grants = 1` is a once-off charge for one request.
 *
 * Use `methods` to offer several settlement rails at once. The list is ordered,
 * and the primary rail comes first. The 402 then carries one Payment challenge
 * per rail, and the client answers exactly one of them:
 *
 *   #[RequiresPayment(amount: '0.50', methods: ['stripe', 'tempo'])]
 *
 * Leave `methods` null to use the default offered set of the package. That set
 * is `mpp.accept`, or `mpp.default_method` when `mpp.accept` is unset. `method`
 * still sets one primary method.
 *
 * `amount`, `currency` and `grants` are optional. Omit them to inherit the
 * global `mpp.defaults`, such as `MPP_DEFAULT_AMOUNT`. Each attribute can
 * override them.
 *
 * `pricing` names the resolvers that adjust the price per request, for example
 * by tier, by region, or by request size. The `amount` here stays the fallback
 * for a request whose resolvers all decline to override the price:
 *
 *   #[RequiresPayment(amount: '5.00', pricing: ['tiered'])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class RequiresPayment
{
    /**
     * @param  list<string>|null  $methods  ordered set of offered settlement methods (primary first)
     * @param  list<string>  $preconditions  named precondition checks for this route. They run after
     *                                       any global checks, and before the gate mints or settles a
     *                                       challenge.
     * @param  list<string>  $pricing  named price resolvers for this route. They run after any global
     *                                 resolvers, and the package applies them to the resolved spec
     *                                 before the spec reaches the gate.
     */
    public function __construct(
        public readonly string|float|null $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $grants = null,
        public readonly ?string $scope = null,
        public readonly ?string $method = null,
        public readonly ?array $methods = null,
        public readonly array $preconditions = [],
        public readonly array $pricing = [],
    ) {}
}
