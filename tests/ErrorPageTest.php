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

it("leaves an app's own 500 page alone", function () {
    config(['app.debug' => false]);
    Route::get('/boom', fn () => throw new RuntimeException('boom'));
    $page = resource_path('views/errors/500.blade.php');
    file_put_contents($page, 'Our own error page');

    try {
        $this->get('/boom')->assertStatus(500)->assertSee('Our own error page')->assertDontSee('Reference');
    } finally {
        unlink($page);
    }
});

it("leaves Laravel's 500 page alone with JOT_ERROR_PAGE=false", function () {
    putenv('JOT_ERROR_PAGE=false');
    $_ENV['JOT_ERROR_PAGE'] = $_SERVER['JOT_ERROR_PAGE'] = 'false';

    try {
        $this->refreshApplication();
    } finally {
        putenv('JOT_ERROR_PAGE');
        unset($_ENV['JOT_ERROR_PAGE'], $_SERVER['JOT_ERROR_PAGE']);
    }

    config(['app.debug' => false]);
    Route::get('/boom', fn () => throw new RuntimeException('boom'));

    $this->get('/boom')->assertStatus(500)->assertSee('Server Error')->assertDontSee('Reference');
});
