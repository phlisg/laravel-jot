# Examples

## Paid event booking

A user books a paid event: they open the event page, fill in their details, and pay with Stripe. Each step is a separate HTTP request, so each step writes its own wide event.

| Request | Fields added by the app |
|---|---|
| `GET /events/{event}` | `booking.event_id`, `booking.event_slug` |
| `POST /events/{event}/details` | `booking.event_id` |
| `POST /events/{event}/pay` | `booking.event_id`, `payment.provider=stripe`, `payment.intent_id`, `payment.status` |
| `POST /stripe/webhook` | `payment.intent_id`, `payment.status` |

The payment step in code, with the `PaymentLog` section from [Sections](fields.md#sections):

```php
jot()->add('booking.event_id', $event->id);

$payment = jot(PaymentLog::class);
$payment->provider = PaymentProvider::Stripe;
$payment->intentId = $intent->id;
$payment->status = PaymentStatus::Approved;
```

Each request adds what it knows, usually from the route. To follow one user through the funnel, query the log backend on `booking.event_id` plus `session.hash` or `user.id`.

> Outside `local` and `testing`, sampling drops successful requests, so the funnel is complete only where every event is written, see [Sampling](../README.md#sampling).

### Linking the Stripe webhook

The Stripe webhook comes from Stripe's servers, so it has no session and no user, and it starts its own trace. To link it to the payment request:

1. When creating the payment intent, store the request's trace ID, `jot()->traceId()`, in the intent `metadata`.
2. In the webhook handler, read it back from the intent and add it as `payment.trace_id`. The name `trace_id` is taken: the webhook event has its own, and automatic fields always win.
3. Search the log backend for `payment.trace_id=<id>` to find the webhook of a payment request.
