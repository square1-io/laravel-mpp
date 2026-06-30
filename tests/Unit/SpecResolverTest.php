<?php

use Illuminate\Http\Request;
use Square1\Mpp\Attributes\RequiresPayment;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\SpecResolver;

beforeEach(function () {
    $this->resolver = new SpecResolver;
});

it('resolves a once-off spec from middleware args', function () {
    $spec = $this->resolver->fromMiddlewareArgs(['0.50', 'USD'], Request::create('/clip'));

    expect($spec->amount)->toBe('0.50')
        ->and($spec->currency)->toBe('USD')
        ->and($spec->grants)->toBe(1)
        ->and($spec->method)->toBe('stripe')
        ->and($spec->scope)->toBe('clip')
        ->and($spec->isMetered())->toBeFalse();
});

it('parses grants and scope options', function () {
    $spec = $this->resolver->fromMiddlewareArgs(['5.00', 'usd', 'grants=10', 'scope=report.basic'], Request::create('/report'));

    expect($spec->grants)->toBe(10)
        ->and($spec->scope)->toBe('report.basic')
        ->and($spec->currency)->toBe('USD')
        ->and($spec->isMetered())->toBeTrue();
});

it('resolves a price_book key', function () {
    config()->set('mpp.price_book', ['report.basic' => ['amount' => '5.00', 'currency' => 'USD', 'grants' => 10]]);

    $spec = $this->resolver->fromMiddlewareArgs(['report.basic'], Request::create('/anything'));

    expect($spec->amount)->toBe('5.00')->and($spec->grants)->toBe(10)->and($spec->scope)->toBe('report.basic');
});

it('resolves from an attribute', function () {
    $spec = $this->resolver->fromAttribute(
        new RequiresPayment(amount: '0.50', currency: 'USD', grants: 3, scope: 'clip.latest'),
        Request::create('/anything')
    );

    expect($spec->amount)->toBe('0.50')->and($spec->grants)->toBe(3)->and($spec->scope)->toBe('clip.latest');
});

it('derives a default scope from the path', function () {
    expect($this->resolver->fromMiddlewareArgs(['0.50', 'USD'], Request::create('/a/b/c'))->scope)->toBe('a.b.c');
});

it('throws without an amount', function () {
    $this->resolver->fromMiddlewareArgs([], Request::create('/x'));
})->throws(InvalidConfigurationException::class);

it('falls back to the global default amount and currency when omitted inline', function () {
    config()->set('mpp.defaults.amount', '0.99');
    config()->set('mpp.defaults.currency', 'eur');

    $spec = $this->resolver->fromMiddlewareArgs(['scope=match'], Request::create('/x'));

    expect($spec->amount)->toBe('0.99')
        ->and($spec->currency)->toBe('EUR')
        ->and($spec->scope)->toBe('match');
});

it('lets an inline amount override the global default', function () {
    config()->set('mpp.defaults.amount', '0.99');

    $spec = $this->resolver->fromMiddlewareArgs(['2.50', 'USD'], Request::create('/x'));

    expect($spec->amount)->toBe('2.50')->and($spec->currency)->toBe('USD');
});

it('throws when no amount is given inline and no global default is set', function () {
    config()->set('mpp.defaults.amount', null);

    $this->resolver->fromMiddlewareArgs(['scope=match'], Request::create('/x'));
})->throws(InvalidConfigurationException::class);

it('reads a positional amount then options when currency is omitted', function () {
    $spec = $this->resolver->fromMiddlewareArgs(['0.50', 'grants=5', 'scope=clip'], Request::create('/x'));

    expect($spec->amount)->toBe('0.50')
        ->and($spec->currency)->toBe('USD')      // default currency, not the option
        ->and($spec->grants)->toBe(5)
        ->and($spec->scope)->toBe('clip');
});

it('applies the global default grants when omitted', function () {
    config()->set('mpp.defaults.grants', 4);

    expect($this->resolver->fromMiddlewareArgs(['0.50', 'USD'], Request::create('/x'))->grants)->toBe(4);
});

it('inherits the global default amount in an attribute that omits it', function () {
    config()->set('mpp.defaults.amount', '1.50');

    $spec = $this->resolver->fromAttribute(new RequiresPayment(scope: 'clip'), Request::create('/x'));

    expect($spec->amount)->toBe('1.50')->and($spec->scope)->toBe('clip');
});

it('throws for an attribute with no amount and no global default', function () {
    config()->set('mpp.defaults.amount', null);

    $this->resolver->fromAttribute(new RequiresPayment(scope: 'clip'), Request::create('/x'));
})->throws(InvalidConfigurationException::class);

it('resolves a price_book key, with global defaults filling an omitted currency', function () {
    config()->set('mpp.defaults.currency', 'gbp');
    config()->set('mpp.price_book', ['report.basic' => ['amount' => '5.00']]); // no currency/grants

    $spec = $this->resolver->fromMiddlewareArgs(['report.basic'], Request::create('/x'));

    expect($spec->amount)->toBe('5.00')->and($spec->currency)->toBe('GBP')->and($spec->grants)->toBe(1);
});

it('resolves a price_book key with a per-route method override', function () {
    config()->set('mpp.default_method', 'stripe');
    config()->set('mpp.price_book', ['report.basic' => ['amount' => '5.00', 'currency' => 'USD', 'grants' => 10]]);

    $spec = $this->resolver->fromMiddlewareArgs(['report.basic', 'method=tempo'], Request::create('/report'));

    expect($spec->amount)->toBe('5.00')
        ->and($spec->currency)->toBe('USD')
        ->and($spec->grants)->toBe(10)
        ->and($spec->scope)->toBe('report.basic')
        ->and($spec->method)->toBe('tempo')
        ->and($spec->offeredMethods)->toBe(['tempo']);
});

it('allows a price_book key option to override the session scope', function () {
    config()->set('mpp.price_book', ['report.basic' => ['amount' => '5.00', 'currency' => 'USD']]);

    $spec = $this->resolver->fromMiddlewareArgs(['report.basic', 'scope=report.monthly'], Request::create('/report'));

    expect($spec->amount)->toBe('5.00')
        ->and($spec->scope)->toBe('report.monthly');
});

it('offers only the default method when accept is unset (back-compat)', function () {
    config()->set('mpp.accept', null);
    config()->set('mpp.methods', ['stripe' => ['verifier' => 'X']]);

    $spec = $this->resolver->fromMiddlewareArgs(['0.50', 'USD'], Request::create('/clip'));

    expect($spec->method)->toBe('stripe')->and($spec->offeredMethods)->toBe(['stripe']);
});

it('offers only the default method when accept is unset even if several methods are configured', function () {
    config()->set('mpp.accept', null);
    config()->set('mpp.default_method', 'stripe');
    config()->set('mpp.methods', [
        'stripe' => ['verifier' => 'X'],
        'tempo' => ['verifier' => 'Y'],
    ]);

    $spec = $this->resolver->fromMiddlewareArgs(['0.50', 'USD'], Request::create('/clip'));

    expect($spec->method)->toBe('stripe')->and($spec->offeredMethods)->toBe(['stripe']);
});

it('treats method as a single-method override when methods is absent', function () {
    config()->set('mpp.accept', ['stripe']);

    $spec = $this->resolver->fromMiddlewareArgs(['0.01', 'USD', 'method=tempo'], Request::create('/clip'));

    expect($spec->method)->toBe('tempo')->and($spec->offeredMethods)->toBe(['tempo']);
});

it('reads the offered set from config(mpp.accept)', function () {
    config()->set('mpp.accept', ['stripe', 'tempo']);

    $spec = $this->resolver->fromMiddlewareArgs(['0.50', 'USD'], Request::create('/clip'));

    expect($spec->method)->toBe('stripe')->and($spec->offeredMethods)->toBe(['stripe', 'tempo']);
});

it('parses a per-route methods= override and keeps the primary first', function () {
    $spec = $this->resolver->fromMiddlewareArgs(
        ['0.50', 'USD', 'methods=tempo|stripe', 'method=stripe'],
        Request::create('/clip')
    );

    // method=stripe hoists stripe to primary even though tempo is listed first.
    expect($spec->method)->toBe('stripe')->and($spec->offeredMethods)->toBe(['stripe', 'tempo']);
});

it('uses the first listed method as primary when no method= is given', function () {
    $spec = $this->resolver->fromMiddlewareArgs(['0.50', 'USD', 'methods=tempo|stripe'], Request::create('/clip'));

    // An explicit methods= list is author-ordered ("primary first"), so its first
    // entry (tempo) is the primary — the configured default_method does NOT
    // override the author's order here (only a config-derived set defers to it).
    expect($spec->method)->toBe('tempo')->and($spec->offeredMethods)->toBe(['tempo', 'stripe']);
});

it('resolves offered methods from a RequiresPayment attribute', function () {
    $spec = $this->resolver->fromAttribute(
        new RequiresPayment(amount: '0.50', methods: ['stripe', 'tempo']),
        Request::create('/clip')
    );

    expect($spec->offeredMethods)->toBe(['stripe', 'tempo']);
});
