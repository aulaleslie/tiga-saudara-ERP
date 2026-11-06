<?php

namespace App\Support\SalesReturn;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a standard Sales Return mutation is attempted on a POS-owned
 * sale/return (or on an ambiguous mixed history). Carries operator guidance.
 */
class SalesReturnWorkflowException extends HttpException
{
    public function __construct(string $message, private readonly ?int $saleId = null)
    {
        parent::__construct(409, $message);
    }

    public function saleId(): ?int
    {
        return $this->saleId;
    }
}
