<?php

namespace App\Support\SalesReturn;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The acting business is not the owner of the document (403). */
class SalesReturnAccessException extends HttpException
{
    public function __construct(string $message = 'Dokumen retur ini bukan milik bisnis yang sedang aktif.')
    {
        parent::__construct(403, $message);
    }
}
