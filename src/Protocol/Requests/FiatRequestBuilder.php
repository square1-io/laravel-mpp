<?php

namespace Square1\Mpp\Protocol\Requests;

use Square1\Mpp\Payment\MethodConfigValidator;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Support\Money;

/**
 * Request payload for fiat rails (draft-stripe-charge shape): amount in the
 * currency's minor units as a string, lowercase ISO currency, and rail
 * identity in methodDetails. The default builder for any method that does not
 * name its own.
 *
 * The Stripe charge method requires both `networkId` and `paymentMethodTypes`
 * in methodDetails; {@see MethodConfigValidator} makes them
 * required config for the Stripe rail, so a stripe challenge always carries
 * both by the time it reaches here. Any other fiat rail using this builder gets
 * whichever of the two it configured, and no methodDetails at all if neither —
 * an empty methodDetails object is never emitted.
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
            // A JSON array on the wire, never an object: reindex so a config
            // written with explicit keys still renders as a list.
            'paymentMethodTypes' => is_array($types) ? array_values($types) : null,
        ], fn ($v) => $v !== null && $v !== []);

        if ($details !== []) {
            $request['methodDetails'] = $details;
        }

        return $request;
    }
}
