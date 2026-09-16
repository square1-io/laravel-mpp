<?php

namespace Square1\Mpp\Discovery;

/**
 * The document-level half of discovery: the OpenAPI `info` and `servers`
 * objects, and the draft's `x-service-info` extension.
 *
 * None of this varies per route, so none of it belongs on a route. It is
 * config — `config('mpp.discovery')` — and where the application already states
 * something (`app.name`, `app.url`), that is the default rather than a second
 * place to keep the same string. An empty value is omitted rather than
 * published blank: `x-service-info` is optional, and a registry reading
 * `{"docs": {}}` learns less than one reading nothing at all.
 */
final class ServiceInfo
{
    /**
     * Settled once per document: `servers()` is read for the document's own
     * `servers` key and again as the base every relative documentation link is
     * resolved against.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $servers = null;

    /**
     * The OpenAPI `info` object. `title` and `version` are required by the
     * draft and always present; the rest appear only when stated.
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $info = [
            'title' => config('mpp.discovery.title') ?: config('app.name', 'API'),
            'version' => (string) config('mpp.discovery.version', '1.0.0'),
        ];

        $info += $this->filter([
            'summary' => config('mpp.discovery.summary'),
            'description' => config('mpp.discovery.description'),
            'termsOfService' => config('mpp.discovery.terms_of_service'),
            'contact' => $this->filter((array) config('mpp.discovery.contact', [])),
            'license' => $this->filter((array) config('mpp.discovery.license', [])),
        ]);

        return $info;
    }

    /**
     * The OpenAPI `servers` array — the base URL a discovered path is relative
     * to. A registry crawling `https://example.com/openapi.json` can infer the
     * origin, but an API served from a path prefix or a separate host cannot be
     * inferred at all, so it is stated. `app.url` is the default because an
     * application that is reachable at all has already set it.
     *
     * Accepts `['https://api.example.com']` or the full OpenAPI form,
     * `[['url' => '…', 'description' => 'Production']]`.
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        if ($this->servers !== null) {
            return $this->servers;
        }

        $stated = config('mpp.discovery.servers');

        if ($stated === null || $stated === [] || $stated === '') {
            $url = config('app.url');

            return $this->servers = is_string($url) && $url !== ''
                ? [['url' => rtrim($url, '/')]]
                : [];
        }

        $servers = [];

        foreach ((array) $stated as $server) {
            if (is_string($server) && $server !== '') {
                $servers[] = ['url' => rtrim($server, '/')];

                continue;
            }

            if (is_array($server) && ($server['url'] ?? '') !== '') {
                $servers[] = $server;
            }
        }

        return $this->servers = $servers;
    }

    /**
     * The draft's `x-service-info` extension: what the service does
     * (`categories`) and where a human or an agent reads more about it
     * (`docs`). Registries index on both. Null when neither is configured.
     *
     * @return array<string, mixed>|null
     */
    public function serviceInfo(): ?array
    {
        $categories = array_values(array_filter(
            array_map('strval', (array) config('mpp.discovery.categories', [])),
            fn (string $category) => $category !== '',
        ));

        $docs = $this->filter([
            'homepage' => $this->absolute(config('mpp.discovery.docs.homepage')),
            'apiReference' => $this->absolute(config('mpp.discovery.docs.api_reference')),
            'llms' => $this->absolute(config('mpp.discovery.docs.llms')),
        ]);

        $info = $this->filter([
            'categories' => $categories,
            'docs' => $docs,
        ]);

        return $info === [] ? null : $info;
    }

    /**
     * Resolve a documentation link against the service's own base URL.
     *
     * The draft requires every `x-service-info` URI to conform to RFC 3986 and
     * schema-types them `format: uri`, which means a scheme: `/llms.txt` is a
     * relative reference, not a URI, and a strict validator rejects it. It also
     * defeats the point — these links exist so a registry that has only the
     * document can follow them, and a registry that has stored `"/"` has
     * nothing to follow.
     *
     * Writing `/llms.txt` in config is the natural thing to do, so it is made
     * absolute rather than refused. Nothing to resolve against leaves it as
     * written: a wrong absolute URL would be worse than an honest relative one.
     */
    private function absolute(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        // Already absolute, or protocol-relative (which carries its own host
        // and must not be re-based).
        if (str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
            return $url;
        }

        $base = $this->servers()[0]['url'] ?? null;

        if (! is_string($base) || $base === '') {
            return $url;
        }

        return rtrim($base, '/').'/'.ltrim($url, '/');
    }

    /**
     * Drop the keys nothing was said about. `false` and `0` survive; null, the
     * empty string and the empty array do not.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function filter(array $values): array
    {
        return array_filter(
            $values,
            fn (mixed $value) => $value !== null && $value !== '' && $value !== [],
        );
    }
}
