<?php

namespace Modules\Purchase\Exceptions;

use DomainException;

class PurchaseSourceOperationNotAllowedException extends DomainException
{
    public static function restrictedSource(string $action, string $sourceType): self
    {
        return new self("Cannot perform [{$action}] on purchase with restricted source [{$sourceType}].");
    }
}
