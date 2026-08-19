<?php

namespace Square1\Mpp\Protocol\Requests;

use Square1\Mpp\Payment\PaymentSpec;

/**
 * Builds the rail-specific `request` payload for one method's challenge —
 * the JSON that ends up base64url-JCS-encoded in the request="…" parameter.
 * Each payment method spec defines its own shape; the builder owns unit
 * conversion (decimal route price -> minor units) for its rail.
 */
interface RailRequestBuilder
{
    /**
     * @param  array<string, mixed>  $config  the `mpp.methods.<method>` block
     * @return array<string, mixed>
     */
    public function build(PaymentSpec $spec, array $config): array;
}
