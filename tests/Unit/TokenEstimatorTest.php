<?php

namespace Ubxty\CoreAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\CoreAi\Support\TokenEstimator;

class TokenEstimatorTest extends TestCase
{
    public function test_estimates_simple_text(): void
    {
        $tokens = TokenEstimator::estimate('Hello world');

        $this->assertGreaterThan(0, $tokens);
    }

    public function test_empty_string_returns_zero(): void
    {
        $tokens = TokenEstimator::estimate('');

        $this->assertSame(0, $tokens);
    }

    public function test_estimate_invocation_returns_expected_keys(): void
    {
        $result = TokenEstimator::estimateInvocation('You are helpful.', 'What is PHP?');

        $this->assertArrayHasKey('input_tokens', $result);
        $this->assertArrayHasKey('available_output', $result);
        $this->assertArrayHasKey('fits', $result);
        $this->assertArrayHasKey('context_window', $result);
    }
}
