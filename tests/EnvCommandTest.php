<?php

use MarvelousDigital\WideEvents\Commands\EnvCommand;

beforeEach(function () {
    $this->files = [base_path('.env.example'), base_path('.env')];
    $this->backups = array_map(fn ($path) => is_file($path) ? file_get_contents($path) : null, $this->files);
});

afterEach(function () {
    foreach ($this->files as $i => $path) {
        $this->backups[$i] === null ? @unlink($path) : file_put_contents($path, $this->backups[$i]);
    }
});

it('adds the missing keys and keeps the existing ones', function () {
    file_put_contents(base_path('.env.example'), "APP_NAME=Laravel\nJOT_SAMPLE_RATE=0.1\n# JOT_STRICT=true\n");
    @unlink(base_path('.env'));

    $this->artisan('jot:env')->assertSuccessful();

    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain("JOT_SAMPLE_RATE=0.1\n")
        ->toContain('JOT_SENTRY_LEVEL=info')
        ->and(substr_count($env, 'JOT_SAMPLE_RATE='))->toBe(1)
        ->and(substr_count($env, 'JOT_STRICT='))->toBe(1)
        ->and(is_file(base_path('.env')))->toBeFalse();
});

it('changes nothing on a second run', function () {
    file_put_contents(base_path('.env'), "APP_NAME=Laravel\n");

    $this->artisan('jot:env');
    $once = file_get_contents(base_path('.env'));
    $this->artisan('jot:env');

    expect(file_get_contents(base_path('.env')))->toBe($once);
});

it('lists only keys that config/jot.php reads', function () {
    $config = file_get_contents(__DIR__.'/../config/jot.php');

    foreach (EnvCommand::KEYS as $key => $value) {
        expect($config)->toContain("env('$key'");
    }
});

it('leaves out the sampling rates, whose default depends on the environment', function () {
    expect(EnvCommand::KEYS)->not->toHaveKeys(['JOT_SAMPLE_RATE', 'JOT_SAMPLE_CLIENT_ERRORS'])
        ->toHaveKeys(['JOT_DAYS', 'JOT_MAX_PER_MINUTE', 'JOT_ERROR_PAGE']);
});
