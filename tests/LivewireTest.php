<?php

namespace MarvelousDigital\WideEvents\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarvelousDigital\WideEvents\Facades\Jot;
use MarvelousDigital\WideEvents\Writer;

class CheckoutForm extends Component
{
    public string $email = '';

    public array $mountedActions = [];

    public function pay(): void
    {
        $this->validate(['email' => 'required|email']);
    }

    public function mountAction(string $name): void
    {
        $this->mountedActions[] = ['name' => $name];
    }

    public function callMountedAction(): void {}

    public function applyCoupon(string $code): void {}

    #[On('cart-updated')]
    public function refreshCart(?string $token = null): void {}

    public function render(): string
    {
        return '<div></div>';
    }
}

function lastEvent(): array
{
    return collect(app(Writer::class)->faked)->last();
}

/**
 * One update request through the HTTP kernel: Livewire::test() skips the middleware.
 */
function update(array $calls = [], array $updates = [], ?Testable $component = null): array
{
    $snapshot = ($component ?? Livewire::test('checkout'))->snapshot;
    $calls = array_map(fn ($params, $method) => ['path' => '', 'method' => $method, 'params' => $params], $calls, array_keys($calls));

    test()->withHeader('X-Livewire', 'true')
        ->post(app('livewire')->getUpdateUri(), ['components' => [['snapshot' => json_encode($snapshot), 'calls' => $calls, 'updates' => $updates]]])
        ->assertOk();

    return lastEvent();
}

beforeEach(function () {
    Livewire::component('checkout', CheckoutForm::class);
    Jot::fake();
});

it('writes the components, calls and updated paths, never the values', function () {
    $event = update(['pay' => []], ['email' => 'jane@example.com']);

    expect($event)->toMatchArray([
        'http.route' => 'default.livewire.update',
        'livewire.components' => ['checkout'],
        'livewire.calls' => ['checkout::pay'],
        'livewire.updates' => ['email'],
    ])
        ->and($event)->toHaveKey('livewire.page')
        ->and(json_encode($event))->not->toContain('jane@example.com');
});

it('writes a failed validation, with the field names', function () {
    expect(update(['pay' => []], ['email' => 'not-an-email']))->toMatchArray([
        'http.response.status_code' => 200,
        'livewire.validation_failed' => true,
        'livewire.validation_fields' => ['email'],
    ])->not->toHaveKey('error.type');
});

it('writes dispatched events', function () {
    $event = update(['__dispatch' => ['cart-updated', []]]);

    expect($event)->toMatchArray(['livewire.events' => ['cart-updated']])
        ->not->toHaveKey('livewire.calls');
});

it('writes the Filament action', function () {
    expect(update(['mountAction' => ['delete']]))->toMatchArray(['filament.action' => 'delete']);

    $mounted = Livewire::test('checkout')->set('mountedActions', [['name' => 'refund']]);

    expect(update(['callMountedAction' => []], component: $mounted))->toMatchArray(['filament.action' => 'refund']);
});

it('adds nothing to the first page load', function () {
    Route::get('/checkout', fn () => Blade::render('<livewire:checkout />'));

    $this->get('/checkout')->assertOk();

    expect(lastEvent())->toMatchArray(['url.path' => '/checkout'])->not->toHaveKey('livewire.components');
});

it('never writes call parameters or event payloads', function () {
    $event = update(['applyCoupon' => ['SECRET-COUPON'], '__dispatch' => ['cart-updated', ['token' => 'tok_secret']]]);

    expect($event)->toMatchArray(['livewire.calls' => ['checkout::applyCoupon'], 'livewire.events' => ['cart-updated']])
        ->and(json_encode($event))->not->toContain('SECRET-COUPON')->not->toContain('tok_secret');
});
