<?php

namespace Square1\Mpp\Exceptions;

/**
 * A request reached the gate with nothing having priced it: the route states no
 * amount and every price resolver declined.
 *
 * Deliberately not an InvalidConfigurationException. The configuration is valid
 * — a resolver is registered and attached — and the outcome depends on the
 * request in hand, so this can recur in production long after deploy rather
 * than surfacing once at boot. Triage should read it as "a resolver returned
 * null for this caller", not "someone mis-wired the config".
 *
 * The usual cause is a resolver meaning to waive the charge and returning null.
 * Null is "no opinion"; waiving is `['free' => true]`.
 */
class UnpriceableRequestException extends MppException {}
