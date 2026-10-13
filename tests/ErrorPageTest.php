<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

it('shows the trace ID on the default 500 page', function () {
    config(['app.debug' => false]);
    Route::get('/boom', fn () => throw new RuntimeException('boom'));

    $response = $this->get('/boom');

    $response->assertStatus(500)
        ->assertSee('Server Error')
        ->assertSee($response->headers->get('X-Request-Id'));
});

it('prints the trace ID with @traceId', function () {
    Route::get('/', fn () => Blade::render('Reference: @traceId'));

    $response = $this->get('/');

    expect($response->getContent())->toBe('Reference: '.$response->headers->get('X-Request-Id'));
});
