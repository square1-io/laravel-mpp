<?php

it('returns a well-formed 402 for an unpaid request', function () {
    $response = $this->get('/clip');

    $response->assertStatus(402)->assertHeader('Content-Type', 'application/problem+json');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('WWW-Authenticate'))->toStartWith('Payment ');

    $response->assertJsonPath('status', 402)
        ->assertJsonPath('title', 'Payment Required')
        ->assertJsonPath('accepts.0.method', 'stripe')
        ->assertJsonPath('accepts.0.amount', '0.50')
        ->assertJsonPath('accepts.0.currency', 'USD')
        ->assertJsonPath('accepts.0.grants', 1)
        ->assertJsonPath('accepts.0.scope', 'clip');
});

it('signs the challenge verifiably', function () {
    $response = $this->get('/clip');

    expect($response->json('challengeId'))->toStartWith('chal_')
        ->and($response->json('accepts.0.sig'))->not->toBeEmpty()
        ->and($response->headers->get('WWW-Authenticate'))->toContain('id="'.$response->json('challengeId').'"');
});

it('advertises the metered bundle and grant count', function () {
    $this->get('/report')
        ->assertStatus(402)
        ->assertJsonPath('accepts.0.amount', '5.00')
        ->assertJsonPath('accepts.0.grants', 10)
        ->assertJsonPath('accepts.0.scope', 'report.basic');
});
