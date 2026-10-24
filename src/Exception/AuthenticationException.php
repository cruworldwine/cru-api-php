<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * 401: the credentials are missing or wrong, the access token has expired, or it could not be refreshed.
 */
class AuthenticationException extends ApiStatusException
{
}
