<?php

namespace Square1\Mpp\Protocol\Requests;

use Square1\Mpp\Payment\MethodConfigValidator;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Support\Money;

/**
 * The request payload for a fiat rail, in the shape of draft-stripe-charge.
 *
 * The payload carries the amount in the minor units of the currency as a string,
 * the ISO currency in lower case, and the identity of the rail in methodDetails.
 * This is the default builder for a method that does not name its own builder.
 *
 * The Stripe charge method requires both `networkId` and `paymentMethodTypes` in
 * methodDetails. {@see MethodConfigValidator} makes them required config for the
 * Stripe rail, so a stripe challenge always carries both when it reaches this
 * class. Another fiat rail that uses this builder receives whichever of the two
 * values it configured. When it configured neither, the builder emits no
 * methodDetails at all, because it never emits an empty methodDetails object.
 */
class FiatRequestBuilder implements RailRequestBuilder
{
    public function build(PaymentSpec $spec, array $config): array
    {
        $request = [
            'amount' => (string) Money::toMinorUnits((string) $spec->amount, $spec->currency),
            'currency' => strtolower($spec->currency),
        ];

        $types = $config['payment_method_types'] ?? null;

        $details = array_filter([
            'networkId' => $config['network_id'] ?? null,
            // This value is a JSON array on the wire, and never an object. Reindex
            // it, so that a config written with explicit keys still renders as a
            // list.
            'paymentMethodTypes' => is_array($types) ? array_values($types) : null,
        ], fn ($v) => $v !== null && $v !== []);

        if ($details !== []) {
            $request['methodDetails'] = $details;
        }

        return $request;
    }
}
