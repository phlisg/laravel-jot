<?php

namespace Phlisg\WideEvents\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Phlisg\WideEvents\Enums\OutputShape;
use Phlisg\WideEvents\Facades\Jot as JotFacade;
use Phlisg\WideEvents\Jot;
use Phlisg\WideEvents\Section;
use Phlisg\WideEvents\Writer;

enum Field: string
{
    case ExperimentVariant = 'experiment.variant';
}

enum Plan: string
{
    case Pro = 'pro';
}

final class Experiment extends Section
{
    public function __construct(public ?string $variant = null) {}
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

    it('keeps the last write, with no warning', function () {
        $warnings = warnings();

        jot()->add('payment.status', 'pending')->add('payment.status', 'declined');

        expect(fields())->toBe(['payment.status' => 'declined'])
            ->and($warnings())->toBe([]);
    });

    it('writes a backed enum value as its value', function () {
        jot()->add('user.plan', Plan::Pro);

        expect(fields())->toBe(['user.plan' => 'pro']);
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

    it('keeps the section already there', function () {
        $first = jot(new Experiment('a'));

        jot()->addIfMissing(new Experiment('b'));

        expect(jot(Experiment::class))->toBe($first);
    });

    it('adds a section when none is there', function () {
        jot()->addIfMissing($experiment = new Experiment('b'));

        expect(jot(Experiment::class))->toBe($experiment);
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

    it('counts each key of a list by the same step', function () {
        jot()->increment(['payment.attempts', 'booking.attempts'], 2);

        expect(fields())->toBe(['payment.attempts' => 2, 'booking.attempts' => 2]);
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

    it('appends the same value to each key of a list', function () {
        jot()->push(['payment.steps', 'booking.steps'], 'charged');

        expect(fields())->toBe(['payment.steps' => ['charged'], 'booking.steps' => ['charged']]);
    });
});

describe('collisions', function () {
    beforeEach(fn () => JotFacade::fake());

    it('keeps the last value of a string key and an enum key with one name, with a warning', function () {
        $warnings = warnings();

        jot()->add('experiment.variant', 'a')->add(Field::ExperimentVariant, 'b');

        expect(fields())->toBe(['experiment.variant' => 'b'])
            ->and($warnings())->toBe(['[jot] Key "experiment.variant" was already added by string, then overwritten by enum.']);
    });

    it('drops a value next to child keys, with a warning', function () {
        $warnings = warnings();

        $event = jot()->initialize(Request::create('/'))
            ->add('payment', 'declined')
            ->add('payment.provider', 'stripe')
            ->emit();

        expect($event)->not->toHaveKey('payment')->toHaveKey('payment.provider', 'stripe')
            ->and($warnings())->toBe(['[jot] Key "payment" was already added by string, then overwritten by its child keys.']);
    });

    it('drops an app value that automatic keys nest under', function () {
        $event = jot()->initialize(Request::create('/'))->add('http', 'checkout')->event();

        expect($event)->not->toHaveKey('http')
            ->and(OutputShape::Nested->apply($event)['http'])->toBeArray();
    });

    it('keeps the automatic value of a key the app writes too, with a warning', function () {
        $warnings = warnings();
        Route::get('/', fn () => jot()->add('user.id', 5) ? 'ok' : 'no');

        $this->actingAs(new GenericUser(['id' => 7]))->get('/')->assertOk();

        expect(app(Writer::class)->faked[0])->toHaveKey('user.id', 7)
            ->and($warnings())->toBe(['[jot] Key "user.id" was already added by string, then overwritten by Jot.']);
    });
});

describe('strict mode', function () {
    it('warns on each string key, never on enum keys or sections', function () {
        config(['jot.strict' => true]);
        $warnings = warnings();

        jot()->add('payment.provider', 'stripe')
            ->add(Field::ExperimentVariant, 'b')
            ->add(new Experiment('a'));

        expect(fields())->toBe(['payment.provider' => 'stripe', 'experiment.variant' => 'b'])
            ->and($warnings())->toBe(['[jot] String key "payment.provider" written; declare it in an enum or a section.']);
    });

    it('is off by default', function () {
        $warnings = warnings();

        jot()->add('payment.provider', 'stripe');

        expect($warnings())->toBe([]);
    });
});

it('starts each request with no fields', function () {
    expect(fields())->toBe([]);
});

it('groups keys by first segment, in order of first appearance', function () {
    jot()->add('payment.provider', 'stripe')
        ->add('booking.event_id', 42)
        ->add('payment.status', 'declined');

    expect(array_keys(jot()->event()))->toBe(['payment.provider', 'payment.status', 'booking.event_id']);
});
