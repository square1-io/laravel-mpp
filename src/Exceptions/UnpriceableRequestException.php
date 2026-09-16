<?php

namespace Square1\Mpp\Exceptions;

/**
 * A request reached the gate and nothing had priced it. The route states no
 * amount, and every price resolver declined.
 *
 * This is deliberately not an InvalidConfigurationException. The configuration
 * is valid, because a resolver is registered and attached. The outcome depends
 * on the request itself. This exception can therefore occur in production long
 * after a deploy, and not once at boot.
 *
 * Read it as "a resolver returned null for this caller", and not as "someone
 * configured the package incorrectly".
 *
 * The usual cause is a resolver that intended to waive the charge and returned
 * null. Null means "no opinion". To waive the charge is `['free' => true]`.
 */
class UnpriceableRequestException extends MppException {}
