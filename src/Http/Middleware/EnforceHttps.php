<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed on plain HTTP. The MPP core and discovery drafts both require
 * TLS: a `402`, its credential, the settlement proofs it carries, and the
 * discovery document all expose payment-sensitive material. The same guard
 * protects every MPP surface (the payment gate and the discovery route), so
 * neither can serve over an unencrypted transport the other refuses.
 *
 * Set `mpp.allow_insecure=true` only for local development and testing. Behind
 * a TLS-terminating proxy, configure trusted proxies so `isSecure()` reflects
 * the real scheme.
 */
class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        self::assert($request);

        return $next($request);
    }

    /**
     * Throw unless the request arrived over TLS (or the insecure escape hatch
     * is set). Shared by the payment gate and the discovery route.
     */
    public static function assert(Request $request): void
    {
        if (! $request->isSecure() && ! config('mpp.allow_insecure', false)) {
            throw new InvalidConfigurationException(
                'MPP requires HTTPS: payment terms and proofs must not travel over unencrypted HTTP. '
                .'Serve this route over TLS (configure trusted proxies if TLS terminates upstream), '
                .'or set MPP_ALLOW_INSECURE=true for local development and testing.'
            );
        }
    }
}
