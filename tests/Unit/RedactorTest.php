<?php

namespace Atoria\Alfred\Tests\Unit;

use Atoria\Alfred\Privacy\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_code_only_never_sends_values_or_bindings(): void
    {
        $r = new Redactor('code_only');

        $this->assertNull($r->text('Jane Doe 07700 900123'));
        $this->assertNull($r->bindings(['jane@example.com']));
        $this->assertSame('Shifts in week ##', $r->label('Shifts in week 42'));
        $this->assertSame('Contact [email]', $r->label('Contact jane@example.com'));
    }

    public function test_redacted_masks_personal_data(): void
    {
        $r = new Redactor('redacted');

        $this->assertSame('Call [phone] or [email], ## shifts', $r->text('Call +44 7700 900123 or jane@example.com, 12 shifts'));
        $this->assertNull($r->bindings([1]));
    }

    public function test_full_sends_everything(): void
    {
        $r = new Redactor('full');

        $this->assertSame('Jane 12', $r->text('Jane 12'));
        $this->assertSame(['booked', 3], $r->bindings(['booked', 3]));
    }

    public function test_unknown_mode_falls_back_to_code_only(): void
    {
        $this->assertSame('code_only', (new Redactor('yolo'))->mode());
    }
}
