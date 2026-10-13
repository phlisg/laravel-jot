# Examples

## Paid event booking

A user books a paid event in three requests: they open the event page, fill in their details, and pay with Stripe. Stripe then confirms the payment with a webhook, a fourth request. Each request writes its own wide event, and the app adds the fields it knows at that step.

| Request | Fields added by the app |
|---|---|
| `GET /events/{event}` | `booking.event_id`, `booking.event_slug` |
| `POST /events/{event}/details` | `booking.event_id` |
| `POST /events/{event}/pay` | `booking.event_id`, `payment.*` |
| `POST /stripe/webhook` | `payment.intent_id`, `payment.status`, `payment.trace_id` |

Jot adds the rest, such as `trace_id`, `http.route`, `user.id` and `session.hash`, see [Automatic fields](fields.md#automatic-fields).

### Steps

1. Declare the payment fields once, as a [section](fields.md#sections) in `app/Jot`, with backed enums for the values to filter on:

   ```php
   namespace App\Jot;

   use MarvelousDigital\WideEvents\Section;

   final class PaymentLog extends Section
   {
       public ?PaymentProvider $provider = null;
       public ?string $intentId = null;
       public ?PaymentStatus $status = null;
       public int $attempts = 0;
       public ?string $traceId = null;
   }

   enum PaymentProvider: string
   {
       case Stripe = 'stripe';
   }

   enum PaymentStatus: string
   {
       case Approved = 'approved';
       case Declined = 'declined';
   }
   ```

2. On the event page, add the event ID and slug from the route. The details form adds `booking.event_id` the same way:

   ```php
   jot()->add([
       'booking.event_id' => $event->id,
       'booking.event_slug' => $event->slug,
   ]);
   ```

3. In the payment controller, fill the section. Store the trace ID of this request in the intent `metadata`, so the webhook can point back to it:

   ```php
   $intent = $stripe->paymentIntents->create([
       'amount' => $event->price_cents,
       'currency' => 'eur',
       'metadata' => ['trace_id' => jot()->traceId()],
   ]);

   jot()->add('booking.event_id', $event->id);

   $payment = jot(PaymentLog::class);
   $payment->provider = PaymentProvider::Stripe;
   $payment->intentId = $intent->id;
   $payment->attempts++;
   ```

   The event of this request holds:

   ```json
   {
     "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
     "http.route": "events.pay",
     "booking.event_id": 42,
     "payment.provider": "stripe",
     "payment.intent_id": "pi_3Nk2",
     "payment.attempts": 1
   }
   ```

4. In the Stripe webhook handler, add the outcome and the trace ID read back from the intent:

   ```php
   $payment = jot(PaymentLog::class);
   $payment->intentId = $request->input('data.object.id');
   $payment->traceId = $request->input('data.object.metadata.trace_id');
   $payment->status = $request->input('type') === 'payment_intent.succeeded'
       ? PaymentStatus::Approved
       : PaymentStatus::Declined;
   ```

   The webhook comes from Stripe's servers, so its event has no session, no user, and a `trace_id` of its own. `payment.trace_id` holds the trace ID of the payment request instead. The name `trace_id` alone is taken by the automatic field, which always wins, see [Key collisions](fields.md#key-collisions).

5. Query the log backend:

   | To find | Query on |
   |---|---|
   | One user's path through the booking | `booking.event_id` plus `session.hash` or `user.id` |
   | The webhook of a payment request | `payment.trace_id=<trace ID of the payment request>` |
   | The payment request of a webhook | `trace_id=<payment.trace_id of the webhook>` |

> Outside `local` and `testing`, sampling drops successful requests by default, so the full path shows only where every event is written, see [Sampling](../README.md#sampling). A `keepWhen` rule keeps more, see [Rules](../README.md#rules).
