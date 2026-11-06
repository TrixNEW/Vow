<?php

declare(strict_types=1);

namespace Vow\Tests;

use Throwable;
use Vow\Promise;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<Throwable> */
    protected array $unhandled = [];

    protected function setUp(): void
    {
        $this->unhandled = [];
        Promise::setRejectionHandler(function (Throwable $reason): void {
            $this->unhandled[] = $reason;
        });
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        Promise::setRejectionHandler(null);
    }
}
