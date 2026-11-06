<?php

namespace App\Support\SalesReturn;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A standard Sales Return action is not allowed in the document's persisted state (409). */
class SalesReturnStateException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(409, $message);
    }
}
