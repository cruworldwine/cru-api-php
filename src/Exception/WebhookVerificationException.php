<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * The request is not a webhook delivery Cru signed with this secret, or it is too old: answer it
 * 401 and drop it.
 */
class WebhookVerificationException extends CruException
{
}
