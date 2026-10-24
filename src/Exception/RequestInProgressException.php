<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * 409 REQUEST_IN_PROGRESS: an earlier request with this reference is still running.
 */
class RequestInProgressException extends ConflictException
{
}
