<?php

namespace Tests\Unit;

use App\Support\CustomerAuth\SmsEncoding;
use App\Support\CustomerAuth\SmsSegmentCalculator;
use PHPUnit\Framework\TestCase;

class SmsSegmentCalculatorTest extends TestCase
{
    public function test_empty_message_has_no_billable_segments(): void
    {
        $estimate = (new SmsSegmentCalculator)->estimate('');

        $this->assertSame(SmsEncoding::Gsm7, $estimate->encoding);
        $this->assertSame(0, $estimate->units);
        $this->assertSame(0, $estimate->segments);
    }

    public function test_gsm7_single_and_concatenated_boundaries_are_counted_in_septets(): void
    {
        $calculator = new SmsSegmentCalculator;

        $single = $calculator->estimate(str_repeat('A', 160));
        $concatenated = $calculator->estimate(str_repeat('A', 161));

        $this->assertSame(SmsEncoding::Gsm7, $single->encoding);
        $this->assertSame(160, $single->units);
        $this->assertSame(1, $single->segments);
        $this->assertSame(161, $concatenated->units);
        $this->assertSame(2, $concatenated->segments);
    }

    public function test_gsm7_extension_characters_consume_two_septets(): void
    {
        $calculator = new SmsSegmentCalculator;

        $single = $calculator->estimate(str_repeat('^', 80));
        $concatenated = $calculator->estimate(str_repeat('€', 81));

        $this->assertSame(SmsEncoding::Gsm7, $single->encoding);
        $this->assertSame(160, $single->units);
        $this->assertSame(1, $single->segments);
        $this->assertSame(162, $concatenated->units);
        $this->assertSame(2, $concatenated->segments);
    }

    public function test_cyrillic_uses_ucs2_boundaries(): void
    {
        $calculator = new SmsSegmentCalculator;

        $single = $calculator->estimate(str_repeat('Я', 70));
        $concatenated = $calculator->estimate(str_repeat('Я', 71));

        $this->assertSame(SmsEncoding::Ucs2, $single->encoding);
        $this->assertSame(70, $single->units);
        $this->assertSame(1, $single->segments);
        $this->assertSame(71, $concatenated->units);
        $this->assertSame(2, $concatenated->segments);
    }

    public function test_emoji_are_counted_as_utf16_surrogate_pairs(): void
    {
        $calculator = new SmsSegmentCalculator;

        $single = $calculator->estimate(str_repeat('🙂', 35));
        $concatenated = $calculator->estimate(str_repeat('🙂', 36));

        $this->assertSame(SmsEncoding::Ucs2, $single->encoding);
        $this->assertSame(70, $single->units);
        $this->assertSame(1, $single->segments);
        $this->assertSame(72, $concatenated->units);
        $this->assertSame(2, $concatenated->segments);
    }
}
