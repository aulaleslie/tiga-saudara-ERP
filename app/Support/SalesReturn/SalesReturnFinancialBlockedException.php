<?php

namespace App\Support\SalesReturn;

/**
 * Financial approval cannot proceed because the source evidence is missing, contradictory or
 * stale. Receiving and custody tracking are unaffected; the message says what to fix.
 */
class SalesReturnFinancialBlockedException extends SalesReturnStateException
{
}
