<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails closed on plain HTTP.
 *
 * The MPP core draft and the discovery draft both require TLS. A 402 response,
 * its credential, the settlement proofs that the credential carries, and the
 * discovery document all expose material that is sensitive to payment.
 *
 * The same guard protects every MPP surface, which is the payment gate and the
 * discovery route. Neither surface can therefore serve over a transport that the
 * other refuses.
 *
 * Set `mpp.allow_insecure=true` for local development and testing only. Behind a
 * proxy that terminates TLS, configure the trusted proxies, so that `isSecure()`
 * reports the real scheme.
 */
class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        self::assert($request);

        return $next($request);
    }

    /**
     * Throws unless the request arrived over TLS.
     *
     * The method also allows a request when the config sets the insecure option.
     * The payment gate and the discovery route share this check.
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
