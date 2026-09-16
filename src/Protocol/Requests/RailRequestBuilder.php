<?php

namespace Square1\Mpp\Protocol\Requests;

use Square1\Mpp\Payment\PaymentSpec;

/**
 * Builds the `request` payload of one method's challenge.
 *
 * The payload is the JSON that the package encodes with JCS and base64url into
 * the request="…" parameter. Each payment method spec defines its own shape. The
 * builder owns the unit conversion for its rail, from the decimal price of the
 * route to minor units.
 */
interface RailRequestBuilder
{
    /**
     * @param  array<string, mixed>  $config  the `mpp.methods.<method>` block
     * @return array<string, mixed>
     */
    public function build(PaymentSpec $spec, array $config): array;
}
