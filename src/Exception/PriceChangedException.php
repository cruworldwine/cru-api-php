<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * 409 PRICE_CHANGED: the price moved since the preview. Preview it again.
 */
class PriceChangedException extends ConflictException
{
}
