<?php

namespace App\Support\SalesReturn;

use RuntimeException;

/** A serial held in standard Sales Return custody was touched by something other than the custody service. */
class HeldSerialException extends RuntimeException
{
}
