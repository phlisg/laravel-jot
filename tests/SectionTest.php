<?php

namespace MarvelousDigital\WideEvents\Tests;

use Illuminate\Http\Request;
use InvalidArgumentException;
use MarvelousDigital\WideEvents\Attributes\Hide;
use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Exceptions\JotSectionPrefixConflict;
use MarvelousDigital\WideEvents\Exceptions\JotSectionRequiresInitialisation;
use MarvelousDigital\WideEvents\Facades\Jot;
use MarvelousDigital\WideEvents\Section;

class MySection extends Section {}

class MyOtherSection extends Section {}

class LoginLog extends Section {}

class SectionLogsSection extends Section {}

final class PaymentLog extends Section
{
    public function __construct(
        public ?PaymentProvider $provider = null,
        public ?string $intentId = null,
        public ?PaymentStatus $status = null,
        public int $attempts = 0,
        public ?int $latencyMs = null,
    ) {}
}

final class RequiredArgs extends Section
{
    public function __construct(
        public ?PaymentProvider $provider,
    ) {}
}

class Payment extends Section
{
    protected static ?string $prefix = 'payment';
}

class StripePayment extends Payment
{
    public function __construct(
        public string $intentId,
        public int $amountCents,
        public int $latencyMs,
    ) {}
}

final class Checkout extends Section
{
    public string $step = 'review';

    #[Hide]
    public string $cardToken = 'tok_secret';

    protected string $internal = 'not exported';
}

final class Route extends Section
{
    #[Key('url.path')]
    public string $path = '/checkout';

    public string $name = 'checkout.store';
}

final class Coupon extends Section
{
    public function __construct(public ?string $code = null) {}
}

final class Cart extends Section
{
    public function __construct(
        public ?Coupon $coupon = null,
        public ?array $steps = null,
    ) {}
}

enum PaymentStatus: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Error = 'error';
}

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Paypal = 'paypal';
}

describe('prefixes', function () {
    it('derives a prefix from the class name, in snake case', function () {
        expect(MySection::prefix())->toBe('my')
            ->and(MyOtherSection::prefix())->toBe('my_other');
    });

    it('drops the Log suffix', function () {
        expect(PaymentLog::prefix())->toBe('payment');
    });

    it('drops Log or Section only at the end of the name', function () {
        expect(LoginLog::prefix())->toBe('login')
            ->and(SectionLogsSection::prefix())->toBe('section_logs');
    });

    it('inherits an explicit prefix from the parent class', function () {
        expect(StripePayment::prefix())->toBe(Payment::prefix());
    });
});

describe('section registry', function () {
    it('returns the section, not the Jot instance', function () {
        expect(jot(new MySection))->toBeInstanceOf(MySection::class);
    });

    it('gives one instance per request', function () {
        $section = jot(new MySection);

        expect(jot(MySection::class))->toBe($section);
    });

    it('returns the same instance from current() as from jot()', function () {
        expect(PaymentLog::current())->toBe(jot(PaymentLog::class));
    });

    it('refuses current() when another class holds the same prefix', function () {
        jot()->add(new StripePayment('pi_1', 100, 10));

        PaymentLog::current();
    })->throws(JotSectionPrefixConflict::class, 'Section '.PaymentLog::class.' shares the prefix "payment" with '.StripePayment::class);

    it('keeps one section per prefix', function () {
        expect(jot(new MySection))->not->toBe(jot(new MyOtherSection));
    });

    it('stores the instance it was given', function () {
        $stripe = new StripePayment('pi_123', 42_00, 120);

        jot()->add($stripe);

        expect(jot(StripePayment::class))->toBe($stripe);
    });

    it('creates a section whose constructor arguments are all optional', function () {
        expect(jot(PaymentLog::class))
            ->toBeInstanceOf(PaymentLog::class)
            ->and(jot(PaymentLog::class)->attempts)->toBe(0);
    });

    it('refuses to create a section with required constructor arguments', function () {
        jot(RequiredArgs::class);
    })->throws(JotSectionRequiresInitialisation::class);

    it('refuses a class that is not a section', function () {
        jot(\stdClass::class);
    })->throws(InvalidArgumentException::class);

    it('replaces a section with a second instance of its class, with no warning', function () {
        $warnings = warnings();

        jot()->add(new StripePayment('pi_1', 100, 10))->add($second = new StripePayment('pi_2', 200, 20));

        expect(jot(StripePayment::class))->toBe($second)
            ->and(jot()->initialize(Request::create('/'))->event())->toMatchArray(['payment.intent_id' => 'pi_2'])
            ->and($warnings())->toBe([]);
    });

    it('keeps one slot for two classes with the same prefix, the last added wins', function () {
        jot(PaymentLog::class);

        jot()->add($stripe = new StripePayment('pi_1', 100, 10));

        expect(jot(PaymentLog::class))->toBe($stripe);
    });

    it('stores a section passed under a key at its own prefix, with a warning on another key', function () {
        $warnings = warnings();

        jot()->add('wrong', $section = new MySection);
        jot()->add(['my_other' => new MyOtherSection]);

        expect(jot(MySection::class))->toBe($section)
            ->and(jot()->initialize(Request::create('/'))->event())->not->toHaveKey('wrong')
            ->and($warnings())->toBe(['[jot] Section '.MySection::class.' was added under key "wrong"; it writes under its own prefix "my".']);
    });
});

describe('in the event', function () {
    it('writes the section properties', function () {
        jot(PaymentLog::class)->provider = PaymentProvider::Stripe;

        expect(jot()->initialize(Request::create('/'))->event())
            ->toMatchArray(['payment.provider' => 'stripe', 'payment.attempts' => 0]);
    });

    it('writes a field set through current()', function () {
        PaymentLog::current()->provider = PaymentProvider::Stripe;

        expect(jot()->initialize(Request::create('/'))->event())
            ->toMatchArray(['payment.provider' => 'stripe']);
    });

    it('keeps the section value over a loose field, whatever the order, with a warning', function (bool $sectionFirst) {
        $warnings = warnings();
        Jot::fake();

        if ($sectionFirst) {
            jot(PaymentLog::class);
        }

        jot()->add('payment.attempts', 9);
        jot(PaymentLog::class);

        expect(jot()->initialize(Request::create('/'))->emit())->toMatchArray(['payment.attempts' => 0])
            ->and($warnings())->toBe(['[jot] Key "payment.attempts" was already added by string, then overwritten by '.PaymentLog::class.'.']);
    })->with(['section first' => true, 'field first' => false]);
});

it('writes through every call to the same instance', function () {
    $payment = jot(PaymentLog::class);
    $payment->provider = PaymentProvider::Stripe;
    $payment->attempts++;

    // Later, in another class, during the same request
    jot(PaymentLog::class)->status = PaymentStatus::Declined;

    expect(jot(PaymentLog::class))->toBe($payment)
        ->and($payment->status)->toBe(PaymentStatus::Declined)
        ->and($payment->attempts)->toBe(1);
});

it('starts each request with an empty registry', function () {
    expect(jot(PaymentLog::class)->attempts)->toBe(0);
});

describe('flattening', function () {
    it('writes the prefix, a dot and the property name in snake case', function () {
        expect((new StripePayment('pi_123', 42_00, 120))->toDotNotation())->toBe([
            'payment.intent_id' => 'pi_123',
            'payment.amount_cents' => 4200,
            'payment.latency_ms' => 120,
        ]);
    });

    it('writes a backed enum as its value and skips null properties', function () {
        $payment = new PaymentLog(provider: PaymentProvider::Stripe, status: PaymentStatus::Declined);

        expect($payment->toDotNotation())->toBe([
            'payment.provider' => 'stripe',
            'payment.status' => 'declined',
            'payment.attempts' => 0,
        ]);
    });

    it('flattens a nested section into deeper keys', function () {
        $cart = new Cart(coupon: new Coupon('SUMMER'));

        expect($cart->toDotNotation())->toBe(['cart.coupon.code' => 'SUMMER']);
    });

    it('exports public properties only, minus the ones marked Hide', function () {
        expect((new Checkout)->toDotNotation())->toBe(['checkout.step' => 'review']);
    });

    it('writes a property marked Key under that exact key, with no prefix', function () {
        expect((new Route)->toDotNotation())->toBe([
            'url.path' => '/checkout',
            'route.name' => 'checkout.store',
        ]);
    });

    it('flattens a map into deeper keys, in snake case', function () {
        $cart = new Cart(steps: ['firstStep' => 'intent', 'nested' => ['innerKey' => 1]]);

        expect($cart->toDotNotation())->toBe(['cart.steps.first_step' => 'intent', 'cart.steps.nested.inner_key' => 1]);
    });

    it('keeps a list as one value', function () {
        $cart = new Cart(steps: ['intent_created', 'charged']);

        expect($cart->toDotNotation())->toBe(['cart.steps' => ['intent_created', 'charged']]);
    });
});
