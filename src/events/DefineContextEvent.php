<?php

declare(strict_types=1);

namespace viesrood\igotissues\events;

use yii\base\Event;

/**
 * Lets the site add its own lines to the context of a report, for example
 * the tenant or the plan a page belongs to.
 */
class DefineContextEvent extends Event
{
    /**
     * Label => value, shown folded under the issue. Values must not be secret.
     *
     * @var array<string, string>
     */
    public array $context = [];
}
