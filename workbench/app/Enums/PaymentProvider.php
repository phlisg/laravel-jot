<?php

namespace Workbench\App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Paypal = 'paypal';
}
