<?php

namespace Phlisg\Jot\Tests;

use Phlisg\Jot\Attributes\Hide;
use Phlisg\Jot\Exceptions\JotSectionRequiresInitialisation;
use Phlisg\Jot\Section;

class MySection extends Section {}

class MyOtherSection extends Section {}

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

    it('keeps a list as one value', function () {
        $cart = new Cart(steps: ['intent_created', 'charged']);

        expect($cart->toDotNotation())->toBe(['cart.steps' => ['intent_created', 'charged']]);
    });
});
