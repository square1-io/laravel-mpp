<?php

namespace Square1\Mpp\Exceptions;

/**
 * A request carried an `Authorization: Payment …` header whose token is not a
 * credential. It is not the session extension, and it is not a base64url JSON
 * object with a `challenge` member and a `payload` member.
 *
 * This is different from an absent credential, which CredentialParser reports as
 * null. A caller with no credential asks what the resource costs, and receives a
 * 402 with a fresh challenge. A caller with a damaged credential has a defect in
 * its client. To answer "payment required" would only produce the same retry.
 * This exception lets the gate answer `malformed-credential` instead.
 */
class MalformedCredentialException extends MppException {}
