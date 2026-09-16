<?php

namespace Square1\Mpp\Discovery;

/**
 * The document-level part of discovery: the OpenAPI `info` and `servers`
 * objects, and the `x-service-info` extension of the draft.
 *
 * None of these values changes per route, so none of them belongs on a route.
 * They are config, under `config('mpp.discovery')`. Where the application
 * already states a value, such as `app.name` or `app.url`, that value is the
 * default. A site owner then keeps the same string in one place only.
 *
 * The class omits an empty value and does not publish it blank.
 * `x-service-info` is optional, and a registry that reads `{"docs": {}}` learns
 * less than a registry that reads no extension.
 */
final class ServiceInfo
{
    /**
     * The servers, settled once per document.
     *
     * The class reads `servers()` for the `servers` key of the document. It
     * reads it again as the base for each relative documentation link.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $servers = null;

    /**
     * Returns the OpenAPI `info` object.
     *
     * The draft requires `title` and `version`, and both are always present.
     * The other fields appear only when a site owner states them.
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
     * Returns the OpenAPI `servers` array.
     *
     * A discovered path is relative to this base URL. A registry that crawls
     * `https://example.com/openapi.json` can infer the origin. It cannot infer
     * the base URL of an API that a path prefix or a different host serves, so
     * a site owner states it. The default is `app.url`, because an application
     * that clients can reach has already set it.
     *
     * The method accepts `['https://api.example.com']` or the full OpenAPI
     * form, `[['url' => '…', 'description' => 'Production']]`.
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
     * Returns the `x-service-info` extension of the draft.
     *
     * `categories` states what the service does. `docs` states where a person
     * or an agent reads more about it. Registries index both fields. The method
     * returns null when the config sets neither field.
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
     * Resolves a documentation link against the base URL of the service.
     *
     * The draft requires every `x-service-info` URI to conform to RFC 3986, and
     * its schema types them `format: uri`. Both require a scheme. `/llms.txt`
     * is a relative reference and not a URI, and a strict validator rejects it.
     *
     * A relative link also defeats the purpose of the field. These links let a
     * registry that holds only the document follow them. A registry that stored
     * `"/"` can follow nothing.
     *
     * A site owner writes `/llms.txt` in the config because that is the natural
     * form. The method therefore makes the link absolute and does not reject
     * it. If there is no base URL to resolve against, the method returns the
     * link as written. An incorrect absolute URL is worse than a relative one.
     */
    private function absolute(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        // The link already carries a scheme, so it is a URI and resolves
        // against nothing.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
            return $url;
        }

        $base = $this->servers()[0]['url'] ?? null;

        if (! is_string($base) || $base === '') {
            return $url;
        }

        return self::resolveReference($base, $url);
    }

    /**
     * Resolves a reference against a base URI, as RFC 3986 §5.3 defines it.
     *
     * The rules that matter here are the ones a site owner meets when the
     * `servers` URL carries a path, such as `https://example.com/api`:
     *
     *   //cdn.example.com/x  takes the scheme of the base, and its own host
     *   /llms.txt            resolves against the ROOT of the host, and drops
     *                        the path of the base
     *   llms.txt             resolves against the directory of the base path
     *
     * An earlier version appended every form to the base, which placed
     * `/llms.txt` inside the path prefix and published a link to a document
     * that is not there.
     */
    private static function resolveReference(string $base, string $reference): string
    {
        $parts = parse_url($base);

        if (! isset($parts['scheme'], $parts['host'])) {
            // The base is not a URI, so there is nothing to resolve against.
            return $reference;
        }

        // A network-path reference carries its own authority and takes only
        // the scheme of the base.
        if (str_starts_with($reference, '//')) {
            return $parts['scheme'].':'.$reference;
        }

        $origin = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        // A query or a fragment on its own keeps the whole path of the base.
        if (str_starts_with($reference, '?') || str_starts_with($reference, '#')) {
            return $origin.($parts['path'] ?? '').$reference;
        }

        // An absolute-path reference replaces the path of the base.
        if (str_starts_with($reference, '/')) {
            return $origin.self::removeDotSegments($reference);
        }

        // A relative-path reference merges with the directory of the base
        // path, which is the base path without its last segment.
        $path = $parts['path'] ?? '';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.self::removeDotSegments(($directory === '' ? '/' : $directory).$reference);
    }

    /**
     * Removes the `.` and `..` segments from a path, as RFC 3986 §5.2.4
     * defines it.
     *
     * A config can reasonably write `../docs`, and a published link must not
     * carry a segment that a client has to resolve for itself.
     */
    private static function removeDotSegments(string $path): string
    {
        $output = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($output);

                continue;
            }

            $output[] = $segment;
        }

        $resolved = implode('/', $output);

        // A path that ends in `.` or `..` keeps its trailing slash, because it
        // named a directory.
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $resolved .= '/';
        }

        return $resolved === '' ? '/' : $resolved;
    }

    /**
     * Removes the keys that the config did not state.
     *
     * `false` and `0` remain. Null, the empty string and the empty array do
     * not.
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
