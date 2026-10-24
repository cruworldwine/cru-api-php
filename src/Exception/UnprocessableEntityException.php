<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * 422: a value was refused (VALIDATION_*), or Cru could not carry it out (TRADE_REFUSED).
 */
class UnprocessableEntityException extends ApiStatusException
{
}
