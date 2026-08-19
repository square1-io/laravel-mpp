<?php

namespace Square1\Mpp\Exceptions;

/**
 * A request carried an `Authorization: Payment …` header whose token is not a
 * credential at all — not the session extension, not a base64url JSON object
 * with `challenge` and `payload`.
 *
 * Distinct from an absent credential, which CredentialParser reports as null.
 * The caller with no credential is asking what it costs and gets a 402 with a
 * fresh challenge; the caller with a garbled one has a client bug, and telling
 * it "payment required" invites an identical retry. This exception is what lets
 * the gate answer `malformed-credential` instead.
 */
class MalformedCredentialException extends MppException {}
