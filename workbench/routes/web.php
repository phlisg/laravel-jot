<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Workbench\App\Enums\PaymentProvider;
use Workbench\App\Enums\PaymentStatus;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\SendReceipt;
use Workbench\App\Jot\PaymentLog;

// No CSRF (cross-site request forgery) check, so curl and jot:demo can post.
// Laravel 13 renamed the middleware to PreventRequestForgery.
Route::withoutMiddleware([PreventRequestForgery::class])->group(function () {
    Route::get('/events/{event}', function (int $event) {
        jot()->add([
            'booking.event_id' => $event,
            'booking.event_slug' => "summer-gala-$event",
        ]);

        return "Event $event";
    })->name('events.show');

    Route::post('/events/{event}/details', function (int $event) {
        jot()->add('booking.event_id', $event);

        return 'Details saved';
    })->name('events.details');

    Route::post('/events/{event}/pay', function (int $event) {
        jot()->add('booking.event_id', $event);

        $payment = PaymentLog::current();
        $payment->provider = PaymentProvider::Stripe;
        $payment->intentId = 'pi_'.Str::random(24);
        $payment->attempts++;
        $payment->latencyMs = 180;
        $payment->status = PaymentStatus::Approved;

        return ['intent_id' => $payment->intentId];
    })->name('events.pay');

    Route::post('/stripe/webhook', function (Request $request) {
        $payment = PaymentLog::current();
        $payment->intentId = $request->input('data.object.id');
        $payment->status = $request->input('type') === 'payment_intent.succeeded'
            ? PaymentStatus::Approved
            : PaymentStatus::Declined;

        return response()->noContent();
    })->name('stripe.webhook');

    Route::post('/jobs/failing', function () {
        ChargeCard::dispatch(42)->onConnection('database');

        return 'Queued, run queue:work to fail it';
    })->name('jobs.failing');

    Route::get('/jobs/sync', function () {
        SendReceipt::dispatchSync(42);

        return 'Receipt sent';
    })->name('jobs.sync');

    Route::post('/login', function (Request $request) {
        return Auth::attempt($request->only('email', 'password'))
            ? 'Welcome'
            : response('Wrong credentials', 401);
    })->name('login');

    Route::get('/register', fn () => Blade::render('<livewire:registration-form />'))->name('register');

    Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)->middleware('web'));

    Route::get('/boom', fn () => throw new RuntimeException('Something broke'))->name('boom');
});
