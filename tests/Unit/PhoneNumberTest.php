<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_it_normalises_to_e164(): void
    {
        $this->assertSame('+2348012345678', PhoneNumber::normalize('+2348012345678'));
        $this->assertSame('+2348012345678', PhoneNumber::normalize('08012345678'));
        $this->assertSame('+2348012345678', PhoneNumber::normalize('2348012345678'));
        $this->assertSame('+2348012345678', PhoneNumber::normalize('0801 234 5678'));
        $this->assertSame('+2348012345678', PhoneNumber::normalize('002348012345678'));
        $this->assertSame('+254711000001', PhoneNumber::normalize('+254711000001'));
    }
}
