<?php

use Illuminate\Support\Facades\Log;
use Square1\Mpp\Tests\Fakes\DocumentStage;

// ── Service-level metadata (x-service-info, info, servers) ───────────────────

it('omits x-service-info when the service describes itself nowhere', function () {
    $doc = $this->get('/openapi.json')->json();

    // The extension is optional, and `{"docs": {}}` tells a registry less than
    // no extension at all.
    expect($doc)->not->toHaveKey('x-service-info');
});

it('publishes the configured categories and documentation links', function () {
    config()->set('mpp.discovery.categories', ['media', 'compute']);
    config()->set('mpp.discovery.docs.homepage', 'https://example.test/docs');
    config()->set('mpp.discovery.docs.api_reference', 'https://example.test/reference');
    config()->set('mpp.discovery.docs.llms', 'https://example.test/llms.txt');

    expect($this->get('/openapi.json')->json('x-service-info'))->toBe([
        'categories' => ['media', 'compute'],
        'docs' => [
            'homepage' => 'https://example.test/docs',
            'apiReference' => 'https://example.test/reference',
            'llms' => 'https://example.test/llms.txt',
        ],
    ]);
});

it('fills the info object from config and leaves unstated fields out', function () {
    config()->set('mpp.discovery.description', 'Paid media endpoints.');
    config()->set('mpp.discovery.terms_of_service', 'https://example.test/terms');
    config()->set('mpp.discovery.contact', ['name' => 'API team', 'email' => null]);
    config()->set('mpp.discovery.license', ['name' => 'MIT', 'identifier' => 'MIT']);

    $info = $this->get('/openapi.json')->json('info');

    expect($info['description'])->toBe('Paid media endpoints.')
        ->and($info['termsOfService'])->toBe('https://example.test/terms')
        ->and($info['contact'])->toBe(['name' => 'API team'])
        ->and($info['license'])->toBe(['name' => 'MIT', 'identifier' => 'MIT']);
});

it('defaults the servers array to the application URL', function () {
    config()->set('app.url', 'https://api.example.test/');

    // A path in the document is relative to something, and an application that
    // is reachable at all has already said where it lives.
    expect($this->get('/openapi.json')->json('servers'))
        ->toBe([['url' => 'https://api.example.test']]);
});

it('takes configured servers in either the short or the full form', function () {
    config()->set('mpp.discovery.servers', [
        'https://api.example.test',
        ['url' => 'https://staging.example.test', 'description' => 'Staging'],
    ]);

    expect($this->get('/openapi.json')->json('servers'))->toBe([
        ['url' => 'https://api.example.test'],
        ['url' => 'https://staging.example.test', 'description' => 'Staging'],
    ]);
});

it('sends the cache and CORS headers the draft recommends', function () {
    $response = $this->get('/openapi.json')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

it('sends neither header when both are configured off', function () {
    config()->set('mpp.discovery.cache_control', null);
    config()->set('mpp.discovery.allow_origin', null);

    $response = $this->get('/openapi.json')->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        // Laravel's own default ("no-cache, private") is left to stand rather
        // than the draft's being forced on a site that turned it off.
        ->and($response->headers->get('Cache-Control'))->not->toContain('max-age=300');
});

// ── Operation-level metadata ────────────────────────────────────────────────

it('documents an operation from the action attribute', function () {
    $operation = $this->get('/openapi.json')->json('paths./doc/clip.get');

    expect($operation['summary'])->toBe('Clip a video')
        ->and($operation['description'])->toBe('Returns a clip of the source video.')
        ->and($operation['tags'])->toBe(['media'])
        ->and($operation['operationId'])->toBe('doc.clip');
});

it('gives each rail its own price note when the note is keyed by method', function () {
    $offers = $this->get('/openapi.json')->json('paths./doc/clip.get.x-payment-info.offers');

    expect($offers[0])->toMatchArray(['method' => 'stripe', 'description' => 'Card, per clip.'])
        ->and($offers[1])->toMatchArray(['method' => 'tempo', 'description' => 'On-chain, per clip.']);
});

it('documents a closure route from the route itself', function () {
    $operation = $this->get('/openapi.json')->json('paths./doc/macro.get');

    expect($operation['summary'])->toBe('Stated on the route')
        // A plain string note documents every offer on the route.
        ->and($operation['x-payment-info']['offers'][0]['description'])->toBe('Flat rate.');
});

it('lets the route outrank the action, field by field', function () {
    // The action already states a summary, a description and tags; the route
    // restates only the summary.
    app('router')->getRoutes()->getByName('doc.clip')->action['mpp_discovery'] = [
        'summary' => 'Stated on the route',
    ];

    $operation = $this->get('/openapi.json')->json('paths./doc/clip.get');

    expect($operation['summary'])->toBe('Stated on the route')
        ->and($operation['description'])->toBe('Returns a clip of the source video.')
        ->and($operation['tags'])->toBe(['media']);
});

it('documents a route it cannot annotate from config, keyed by route name', function () {
    config()->set('mpp.discovery.operations', [
        'doc.named' => ['summary' => 'Stated in config', 'priceNote' => 'Per call.'],
    ]);

    $operation = $this->get('/openapi.json')->json('paths./doc/named.get');

    expect($operation['summary'])->toBe('Stated in config')
        ->and($operation['x-payment-info']['offers'][0]['description'])->toBe('Per call.');
});

it('documents a route from config keyed by method and path', function () {
    config()->set('mpp.discovery.operations', [
        'GET /doc/macro' => ['tags' => ['media']],
    ]);

    $operation = $this->get('/openapi.json')->json('paths./doc/macro.get');

    expect($operation['tags'])->toBe(['media'])
        // The route's own summary still wins over the config block.
        ->and($operation['summary'])->toBe('Stated on the route');
});

it('keeps a hidden route out of the document without making it free', function () {
    $paths = $this->get('/openapi.json')->json('paths');

    expect($paths)->not->toHaveKey('/doc/unlisted');

    // Unlisted, still payable.
    $this->get('/doc/unlisted')->assertStatus(402);
});

it('uses the route name as the operationId', function () {
    expect($this->get('/openapi.json')->json('paths./doc/named.get.operationId'))
        ->toBe('doc.named');
});

it('omits the operationId for a route with no name', function () {
    expect($this->get('/openapi.json')->json('paths./doc/macro.get'))
        ->not->toHaveKey('operationId');
});

// ── Docblocks ───────────────────────────────────────────────────────────────

it('leaves docblocks unpublished unless asked', function () {
    // A docblock is written for colleagues. Publishing it to an unauthenticated
    // endpoint that registries crawl has to be a decision, not an upgrade.
    expect($this->get('/openapi.json')->json('paths./doc/summarise.get'))
        ->not->toHaveKey('summary');
});

it('reads the summary and description off the docblock when enabled', function () {
    config()->set('mpp.discovery.docblocks', true);

    $operation = $this->get('/openapi.json')->json('paths./doc/summarise.get');

    expect($operation['summary'])->toBe('Summarise a transcript.')
        ->and($operation['description'])->toContain('Reads the whole transcript')
        // Annotation tags are for the reader of the code, not for an agent
        // deciding whether to pay.
        ->and($operation['description'])->not->toContain('@param');
});

// ── Path parameters ─────────────────────────────────────────────────────────

it('declares path parameters, and never publishes a Laravel optional marker', function () {
    $paths = $this->get('/openapi.json')->json('paths');

    // An OpenAPI path parameter is always required, so one Laravel route with
    // an optional segment is two OpenAPI paths rather than one optional one.
    expect($paths)->toHaveKey('/doc/report/{year}')
        ->and($paths)->toHaveKey('/doc/report/{year}/{month}')
        ->and(array_keys($paths))->not->toContain('/doc/report/{year}/{month?}');

    expect($paths['/doc/report/{year}']['get']['parameters'])->toBe([
        ['name' => 'year', 'in' => 'path', 'required' => true, 'schema' => [
            'type' => 'string',
            // A `where()` constraint matches the whole segment; an unanchored
            // JSON Schema pattern would not.
            'pattern' => '^(?:[0-9]{4})$',
        ]],
    ]);

    expect(array_column($paths['/doc/report/{year}/{month}']['get']['parameters'], 'name'))
        ->toBe(['year', 'month']);
});

it('drops the operationId from a route that serves several paths', function () {
    // OpenAPI requires operationId to be unique across the document, and the
    // two paths of an optional parameter are two operations.
    expect($this->get('/openapi.json')->json('paths./doc/report/{year}.get'))
        ->not->toHaveKey('operationId');
});

it('describes a path parameter from the operation metadata', function () {
    config()->set('mpp.discovery.operations', [
        'GET /doc/report/{year}/{month?}' => ['parameters' => ['year' => 'Four-digit year.']],
    ]);

    $parameters = $this->get('/openapi.json')->json('paths./doc/report/{year}.get.parameters');

    expect($parameters[0]['description'])->toBe('Four-digit year.')
        ->and($parameters[0]['schema']['pattern'])->toBe('^(?:[0-9]{4})$');
});

it('declares query parameters the operation states', function () {
    config()->set('mpp.discovery.operations', [
        'doc.named' => ['query' => [
            'format' => 'Output format.',
            'page' => ['required' => true, 'schema' => ['type' => 'integer']],
        ]],
    ]);

    $parameters = $this->get('/openapi.json')->json('paths./doc/named.get.parameters');

    expect($parameters)->toBe([
        ['name' => 'format', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Output format.'],
        ['required' => true, 'schema' => ['type' => 'integer'], 'name' => 'page', 'in' => 'query'],
    ]);
});

// ── Input and output schemas ────────────────────────────────────────────────

it('derives the input schema from the FormRequest the action type-hints', function () {
    // Nothing on this route says anything about its body; the rules do.
    $schema = $this->get('/openapi.json')
        ->json('paths./doc/derived.post.requestBody.content.application/json.schema');

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['url', 'seconds', 'format', 'watermark'])
        ->and($schema['properties']['url'])->toBe(['type' => 'string', 'format' => 'uri'])
        ->and($schema['properties']['seconds'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 60])
        // `required|in:…` names no type, so none is claimed — an `in` rule
        // constrains values, not the type they are.
        ->and($schema['properties']['format'])->toBe(['enum' => ['mp4', 'webm']])
        ->and($schema['properties']['caption'])->toBe(['type' => ['string', 'null'], 'maxLength' => 120])
        ->and($schema['properties']['tags'])->toBe(['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string']])
        ->and($schema['properties']['watermark'])->toBe([
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'opacity' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['text'],
        ]);
});

it('marks a body required when the derived schema has required properties', function () {
    expect($this->get('/openapi.json')->json('paths./doc/derived.post.requestBody.required'))
        ->toBeTrue();
});

it('falls back to a permissive body schema when form requests are off', function () {
    config()->set('mpp.discovery.form_requests', false);

    expect($this->get('/openapi.json')->json('paths./doc/derived.post.requestBody'))
        ->toBe(['content' => ['application/json' => ['schema' => ['type' => 'object']]]]);
});

it('publishes a stated output schema on the 200', function () {
    $responses = $this->get('/openapi.json')->json('paths./doc/clip.get.responses');

    expect($responses['200']['content']['application/json']['schema'])->toBe([
        'type' => 'object',
        'required' => ['url'],
        'properties' => ['url' => ['type' => 'string', 'format' => 'uri']],
    ]);

    // The draft requires a 402 on every payable operation whatever else is said.
    expect($responses)->toHaveKey('402');
});

it('publishes a response map keyed by status code', function () {
    config()->set('mpp.discovery.operations', [
        'doc.named' => ['response' => [
            '200' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            '404' => ['description' => 'No such report.'],
        ]],
    ]);

    $responses = $this->get('/openapi.json')->json('paths./doc/named.get.responses');

    expect($responses['200']['content']['application/json']['schema']['properties'])
        ->toBe(['id' => ['type' => 'string']])
        ->and($responses['404'])->toBe(['description' => 'No such report.'])
        ->and($responses)->toHaveKey('402');
});

it('keeps the operation when a schema class cannot be read, and says why', function () {
    Log::spy();

    config()->set('mpp.discovery.operations', [
        'doc.named' => ['summary' => 'Still here', 'response' => 'App\\Nope'],
    ]);

    $operation = $this->get('/openapi.json')->assertOk()->json('paths./doc/named.get');

    expect($operation['summary'])->toBe('Still here')
        ->and($operation['responses']['200'])->toBe(['description' => 'Successful response']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'App\\Nope'));
});

// ── The pipeline ────────────────────────────────────────────────────────────

it('hands the finished document to the configured pipeline', function () {
    config()->set('mpp.discovery.pipeline', [[DocumentStage::class, 'components']]);

    expect($this->get('/openapi.json')->json('components'))
        ->toBe(['schemas' => ['Clip' => ['type' => 'object']]]);
});

it('serves the document anyway when a pipeline stage throws', function () {
    Log::spy();

    config()->set('mpp.discovery.pipeline', [
        [DocumentStage::class, 'explode'],
        [DocumentStage::class, 'components'],
    ]);

    // A broken post-processor costs the document its post-processing, not its
    // listing — and the stages after it still run.
    $doc = $this->get('/openapi.json')->assertOk()->json();

    expect($doc['openapi'])->toBe('3.1.0')
        ->and($doc)->toHaveKey('components');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'explode'));
});

it('ignores a pipeline stage that does not return the document', function () {
    Log::spy();

    config()->set('mpp.discovery.pipeline', [[DocumentStage::class, 'wrongReturn']]);

    expect($this->get('/openapi.json')->assertOk()->json('openapi'))->toBe('3.1.0');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'wrongReturn'));
});

it('does not turn a FormRequest on a bodiless verb into a request body', function () {
    // The same action is reachable by GET and by POST. Only the POST carries a
    // body, so only the POST gets the rules as one.
    $paths = $this->get('/openapi.json')->json('paths./doc/derived');

    expect($paths['post'])->toHaveKey('requestBody')
        ->and($paths['get'])->not->toHaveKey('requestBody');
});

it('makes a relative documentation link absolute against the service URL', function () {
    config()->set('mpp.discovery.servers', ['https://mpp.example.test']);
    config()->set('mpp.discovery.docs.homepage', '/');
    config()->set('mpp.discovery.docs.api_reference', 'openapi.json');
    config()->set('mpp.discovery.docs.llms', '/llms.txt');

    // The draft schema-types these `format: uri`, so a relative reference fails
    // a strict validator — and a registry that stored "/" has nothing to follow.
    expect($this->get('/openapi.json')->json('x-service-info.docs'))->toBe([
        'homepage' => 'https://mpp.example.test/',
        'apiReference' => 'https://mpp.example.test/openapi.json',
        'llms' => 'https://mpp.example.test/llms.txt',
    ]);
});

it('leaves an absolute or protocol-relative documentation link alone', function () {
    config()->set('mpp.discovery.servers', ['https://mpp.example.test']);
    config()->set('mpp.discovery.docs.homepage', 'https://docs.example.test/guide');
    config()->set('mpp.discovery.docs.llms', '//cdn.example.test/llms.txt');

    expect($this->get('/openapi.json')->json('x-service-info.docs'))->toBe([
        'homepage' => 'https://docs.example.test/guide',
        'llms' => '//cdn.example.test/llms.txt',
    ]);
});

// ── Free routes ─────────────────────────────────────────────────────────────

it('lists only payment-gated routes by default', function () {
    $paths = $this->get('/openapi.json')->json('paths');

    expect($paths)->not->toHaveKey('/free/health')
        ->and($paths)->not->toHaveKey('/free/redirect/{slug}');
});

it('lists a free route that config names, with no payment extension', function () {
    config()->set('mpp.discovery.include', ['free.redirect']);

    $operation = $this->get('/openapi.json')->json('paths./free/redirect/{slug}.get');

    // Free means free: no offers to mislead a client into paying, and no 402
    // the route will never send.
    expect($operation)->not->toHaveKey('x-payment-info')
        ->and($operation['responses'])->not->toHaveKey('402')
        ->and($operation['operationId'])->toBe('free.redirect')
        ->and($operation['parameters'][0]['name'])->toBe('slug');
});

it('matches an include pattern by name, by path and by wildcard', function () {
    config()->set('mpp.discovery.include', ['GET /free/health']);
    expect($this->get('/openapi.json')->json('paths'))->toHaveKey('/free/health');

    config()->set('mpp.discovery.include', ['free/*']);
    $paths = $this->get('/openapi.json')->json('paths');

    expect($paths)->toHaveKey('/free/health')
        ->and($paths)->toHaveKey('/free/redirect/{slug}');
});

it('documents a free route the same way it documents a paid one', function () {
    config()->set('mpp.discovery.include', ['free.redirect']);
    config()->set('mpp.discovery.operations', [
        'free.redirect' => [
            'summary' => 'Redirect a short link (free)',
            'response' => [
                '307' => ['description' => 'Redirect to the destination URL'],
                '404' => ['description' => 'Unknown short link'],
            ],
        ],
    ]);

    $operation = $this->get('/openapi.json')->json('paths./free/redirect/{slug}.get');

    expect($operation['summary'])->toBe('Redirect a short link (free)')
        // A route that named its own responses does not also get a 200 it never
        // returns.
        ->and($operation['responses'])->toBe([
            '307' => ['description' => 'Redirect to the destination URL'],
            '404' => ['description' => 'Unknown short link'],
        ]);
});

it('never lists the discovery document itself', function () {
    config()->set('mpp.discovery.include', ['*']);

    expect($this->get('/openapi.json')->json('paths'))->not->toHaveKey('/openapi.json');
});

it('keeps a hidden free route unlisted', function () {
    config()->set('mpp.discovery.include', ['*']);
    config()->set('mpp.discovery.operations', ['free.redirect' => ['hidden' => true]]);

    expect($this->get('/openapi.json')->json('paths'))
        ->not->toHaveKey('/free/redirect/{slug}');
});

it('lets a route say false where a lower-precedence source said true', function () {
    // `hidden` and `deprecated` are nullable like every other field, so "said
    // nothing" and "said no" are different answers and the nearest-to-the-route
    // rule holds for them too.
    config()->set('mpp.discovery.include', ['free/*']);
    config()->set('mpp.discovery.operations', ['free.redirect' => ['hidden' => true]]);

    app('router')->getRoutes()->getByName('free.redirect')->action['mpp_discovery'] = [
        'hidden' => false,
    ];

    expect($this->get('/openapi.json')->json('paths'))->toHaveKey('/free/redirect/{slug}');
});
