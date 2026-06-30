<?php

use Square1\Mpp\Protocol\CredentialParser;

beforeEach(function () {
    $this->parser = new CredentialParser;
});

it('parses an SPT credential with signature', function () {
    $c = $this->parser->parse('Payment method="stripe", challengeId="chal_1", sig="abc", spt="spt_1"');

    expect($c)->not->toBeNull()
        ->and($c->challengeId)->toBe('chal_1')
        ->and($c->spt)->toBe('spt_1')
        ->and($c->signature)->toBe('abc')
        ->and($c->isSpt())->toBeTrue();
});

it('parses a session credential', function () {
    $c = $this->parser->parse('Payment method="stripe", session="sess_1"');

    expect($c->session)->toBe('sess_1')
        ->and($c->isSession())->toBeTrue()
        ->and($c->isSpt())->toBeFalse();
});

it('returns null for non-payment schemes', function (?string $header) {
    expect($this->parser->parse($header))->toBeNull();
})->with(['Bearer x', 'Basic x', [null], ['']]);

it('parses a rail-neutral proof credential', function () {
    $c = $this->parser->parse('Payment method="tempo", challengeId="chal_1", sig="abc", proof="0xdeadbeef"');

    expect($c->method)->toBe('tempo')
        ->and($c->proof)->toBe('0xdeadbeef')
        ->and($c->proof())->toBe('0xdeadbeef')
        ->and($c->isSettlementProof())->toBeTrue()
        // proof is not an SPT, so the Stripe-specific accessors stay false.
        ->and($c->isSpt())->toBeFalse()
        ->and($c->spt)->toBeNull();
});

it('treats spt as an alias for proof', function () {
    $c = $this->parser->parse('Payment method="stripe", challengeId="chal_1", sig="abc", spt="spt_1"');

    expect($c->spt)->toBe('spt_1')
        ->and($c->proof)->toBeNull()
        // proof() falls back to the SPT, isSettlementProof() agrees with isSpt().
        ->and($c->proof())->toBe('spt_1')
        ->and($c->isSpt())->toBeTrue()
        ->and($c->isSettlementProof())->toBeTrue();
});

it('prefers an explicit proof over spt when both are present', function () {
    $c = $this->parser->parse('Payment method="stripe", spt="spt_1", proof="0xabc"');

    expect($c->proof())->toBe('0xabc');
});
