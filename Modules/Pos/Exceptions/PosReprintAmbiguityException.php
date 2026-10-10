<?php

namespace Modules\Pos\Exceptions;

use Throwable;

class PosReprintAmbiguityException extends PosReprintProjectionException
{
    public function __construct(
        string $message = 'Struk tidak dapat dicetak ulang dengan harga terkini karena pemetaan baris transaksi historis ambigu. Silakan lihat struk historis checkout asli.',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
