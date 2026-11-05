<?php

declare(strict_types=1);

namespace RC\Helper\Throttler;

/** @internal Exact integer token accounting; cache locking and schema validation belong to Throttler. */
final class Bucket
{
    /** Return [allowed, next state, retry seconds] without side effects or floating-point token credit. */
    public static function take(array $state, int $now, int $cost): array
    {
        $capacity = $state['capacity'];
        $seconds = $state['seconds'];
        $at = max($now, $state['at']);
        $elapsed = $at - $state['at'];
        if ($elapsed >= $seconds) {
            $state['tokens'] = $capacity;
            $state['remainder'] = 0;
        } elseif ($elapsed > 0) {
            [$whole, $fraction] = self::multiplyDivide($elapsed, $capacity, $seconds);
            if ($fraction >= $seconds - $state['remainder']) {
                $fraction -= $seconds - $state['remainder'];
                ++$whole;
            } else { $fraction += $state['remainder']; }
            if ($whole >= $capacity - $state['tokens']) {
                $state['tokens'] = $capacity;
                $state['remainder'] = 0;
            } else {
                $state['tokens'] += $whole;
                $state['remainder'] = $fraction;
            }
        }
        $state['at'] = $at;
        if ($state['tokens'] >= $cost) {
            $state['tokens'] -= $cost;
            return [true, $state, 0];
        }
        // ceil(((cost - tokens) * seconds - fractional_credit) / capacity), without overflowing the product.
        [$wait, $fraction] = self::multiplyDivide($cost - $state['tokens'], $seconds, $capacity);
        $wait -= intdiv($state['remainder'], $capacity);
        $subtract = $state['remainder'] % $capacity;
        if ($fraction < $subtract) { --$wait; $fraction += $capacity - $subtract; }
        else { $fraction -= $subtract; }
        if ($fraction > 0) { ++$wait; }
        $wait = max(1, $wait);
        $rollback = $at - $now;
        return [false, $state, $rollback > PHP_INT_MAX - $wait ? PHP_INT_MAX : $rollback + $wait];
    }

    /** Compute [floor(a*b/d), remainder] for 0 <= a <= d; only overflowing products use modular doubling. */
    private static function multiplyDivide(int $a, int $b, int $d): array
    {
        if ($a === 0 || $b === 0) { return [0, 0]; }
        if ($a === $d) { return [$b, 0]; }
        if ($a <= intdiv(PHP_INT_MAX, $b)) {
            $product = $a * $b;
            return [intdiv($product, $d), $product % $d];
        }
        // Split b into whole divisor multiples and a residue; a <= d bounds the final quotient by b.
        // Residue doubling uses subtraction/carry rather than overflowing an addition or product.
        $quotient = $a * intdiv($b, $d);
        $remainder = 0;
        $partQuotient = 0;
        $partRemainder = $b % $d;
        while ($a > 0) {
            if ($a & 1) {
                $quotient += $partQuotient;
                if ($remainder >= $d - $partRemainder) { $remainder -= $d - $partRemainder; ++$quotient; }
                else { $remainder += $partRemainder; }
            }
            $a = intdiv($a, 2);
            if ($a === 0) { break; }
            $partQuotient *= 2;
            if ($partRemainder >= $d - $partRemainder) { $partRemainder -= $d - $partRemainder; ++$partQuotient; }
            else { $partRemainder *= 2; }
        }
        return [$quotient, $remainder];
    }
}
