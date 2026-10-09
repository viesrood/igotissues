<?php

declare(strict_types=1);

namespace viesrood\igotissues\events;

use Throwable;
use yii\base\Event;

/**
 * Raised before an error is recorded. Set $isValid to false for an exception
 * that is expected behaviour on this site and should not become a report.
 */
class CaptureEvent extends Event
{
    public Throwable $exception;

    public bool $isValid = true;
}
