<?php

use Illuminate\Support\Facades\File;

afterEach(fn () => File::deleteDirectory(app_path('Jot')));

it('creates a section class in app/Jot', function () {
    $this->artisan('jot:section', ['name' => 'StripePayment'])->assertSuccessful();

    expect(File::get(app_path('Jot/StripePayment.php')))
        ->toContain('namespace App\Jot;')
        ->toContain('final class StripePayment extends Section');
});

it('keeps a nested name under app/Jot', function () {
    $this->artisan('jot:section', ['name' => 'Billing/Invoice'])->assertSuccessful();

    expect(File::get(app_path('Jot/Billing/Invoice.php')))->toContain('namespace App\Jot\Billing;');
});
