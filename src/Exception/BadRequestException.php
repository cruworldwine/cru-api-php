<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * 400: the request could not be understood (VALIDATION_UNKNOWN_PARAMETER, VALIDATION_MALFORMED_BODY).
 */
class BadRequestException extends ApiStatusException
{
}
