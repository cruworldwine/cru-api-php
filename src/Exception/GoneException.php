<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * 410: the confirmation code has expired or was cancelled.
 */
class GoneException extends ApiStatusException
{
}
