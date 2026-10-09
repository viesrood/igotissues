<?php

declare(strict_types=1);

namespace viesrood\igotissues\github;

use RuntimeException;

/**
 * A GitHub call that did not work. The message is safe to log, never to show:
 * it can carry part of GitHub's own answer.
 */
class GitHubException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
