## Specs

No new capabilities or modified requirements. This is a bug fix (correcting an incorrect relationship reference in the receipt service to match the actual model definition).

The `PosCheckoutPayment::paymentMethod()` relationship was already correctly defined; the receipt service was simply using the wrong name to access it.
