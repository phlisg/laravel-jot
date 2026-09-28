<?php

namespace Phlisg\Jot\Tests;

use Phlisg\Jot\Jot;

enum Field: string
{
    case ExperimentVariant = 'experiment.variant';
}

/**
 * The fields the accumulator holds, before emit flattens the sections into them.
 */
function fields(): array
{
    return (fn () => $this->fields)->call(app(Jot::class));
}

describe('add', function () {
    it('writes a string key', function () {
        jot()->add('payment.provider', 'stripe');

        expect(fields())->toBe(['payment.provider' => 'stripe']);
    });

    it('writes a backed enum key as its value', function () {
        jot()->add(Field::ExperimentVariant, 'b');

        expect(fields())->toBe(['experiment.variant' => 'b']);
    });

    it('writes a map of keys in one call', function () {
        jot()->add([
            'booking.event_id' => 42,
            'booking.event_slug' => 'jazz-night',
        ]);

        expect(fields())->toBe([
            'booking.event_id' => 42,
            'booking.event_slug' => 'jazz-night',
        ]);
    });

    it('keeps the last write', function () {
        jot()->add('payment.status', 'pending')->add('payment.status', 'declined');

        expect(fields())->toBe(['payment.status' => 'declined']);
    });
});

describe('addIfMissing', function () {
    it('writes a key nothing has taken', function () {
        jot()->addIfMissing('payment.currency', 'eur');

        expect(fields())->toBe(['payment.currency' => 'eur']);
    });

    it('keeps the first write', function () {
        jot()->add('payment.provider', 'stripe')->addIfMissing('payment.provider', 'paypal');

        expect(fields())->toBe(['payment.provider' => 'stripe']);
    });
});

describe('increment', function () {
    it('starts an absent counter at one', function () {
        jot()->increment('payment.attempts');

        expect(fields())->toBe(['payment.attempts' => 1]);
    });

    it('counts up on each call', function () {
        jot()->increment('payment.attempts')->increment('payment.attempts');

        expect(fields())->toBe(['payment.attempts' => 2]);
    });

    it('accepts a step', function () {
        jot()->increment('payment.attempts', 5);

        expect(fields())->toBe(['payment.attempts' => 5]);
    });

    it('leaves a non-numeric field alone', function () {
        jot()->add('payment.provider', 'stripe')->increment('payment.provider');

        expect(fields())->toBe(['payment.provider' => 'stripe']);
    });
});

describe('push', function () {
    it('appends to a list, in order', function () {
        jot()->push('payment.steps', 'intent_created')->push('payment.steps', 'charged');

        expect(fields())->toBe(['payment.steps' => ['intent_created', 'charged']]);
    });

    it('keeps duplicates', function () {
        jot()->push('payment.steps', 'charged')->push('payment.steps', 'charged');

        expect(fields())->toBe(['payment.steps' => ['charged', 'charged']]);
    });
});

it('starts each request with no fields', function () {
    expect(fields())->toBe([]);
});
