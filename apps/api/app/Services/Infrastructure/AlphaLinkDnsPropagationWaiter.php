<?php

namespace App\Services\Infrastructure;

use Closure;
use RuntimeException;

/** Retries DNS assertions briefly while provider changes reach public resolvers. */
class AlphaLinkDnsPropagationWaiter
{
    /** @var Closure(int): void */
    private readonly Closure $pause;

    public function __construct(
        private readonly int $maxAttempts = 12,
        private readonly int $intervalMicroseconds = 5_000_000,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (int $microseconds): void {
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        };
    }

    /** @param callable(): void $assertion */
    public function retry(callable $assertion): void
    {
        $attempts = max(1, $this->maxAttempts);
        $lastFailure = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $assertion();

                return;
            } catch (RuntimeException $exception) {
                $lastFailure = $exception;
            }

            if ($attempt < $attempts) {
                ($this->pause)(max(0, $this->intervalMicroseconds));
            }
        }

        throw $lastFailure ?? new RuntimeException('Public DNS verification failed.');
    }
}
