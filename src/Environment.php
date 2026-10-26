<?php

declare(strict_types=1);

namespace CruWorldWine;

/**
 * Where a client connects: Cru's live markets, or the sandbox.
 */
enum Environment: string
{
    /**
     * https://{market}.cruworldwine.com/api/v1, where trades are real. The default.
     */
    case Live = 'live';

    /**
     * https://{market}.crusandbox.com/api/v1, for building and testing: no real money or wine
     * changes hands there. It has the same markets as live, and takes credentials issued for it.
     */
    case Sandbox = 'sandbox';
}
