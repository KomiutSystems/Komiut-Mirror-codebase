<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phone — every accepted form of the SAME Kenyan mobile must collapse to one
 * canonical local number, and to one Daraja msisdn. These are the inputs the
 * register / login / reset / pay screens actually send.
 */
final class PhoneTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function acceptedForms(): array
    {
        return [
            'local' => ['0712345678', '0712345678'],
            'plus 254' => ['+254712345678', '0712345678'],
            'bare 254' => ['254712345678', '0712345678'],
            'missing zero' => ['712345678', '0712345678'],
            'spaces' => ['0712 345 678', '0712345678'],
            'dashes' => ['0712-345-678', '0712345678'],
            'plus 254 spaced' => ['+254 712 345 678', '0712345678'],
            '01 range (Airtel)' => ['0112345678', '0112345678'],
            '01 via 254' => ['254112345678', '0112345678'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedForms')]
    public function it_collapses_every_form_to_canonical_local(string $raw, string $expected): void
    {
        $this->assertSame($expected, Phone::normalise($raw));
        $this->assertTrue(Phone::isValid($raw));
    }

    #[Test]
    #[DataProvider('acceptedForms')]
    public function it_gives_the_daraja_msisdn_for_every_form(string $raw, string $expectedLocal): void
    {
        $expectedMsisdn = '254'.substr($expectedLocal, 1);
        $this->assertSame($expectedMsisdn, Phone::msisdn($raw));
    }

    /** @return array<string, array{0: ?string}> */
    public static function rejectedForms(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'too short' => ['071234567'],
            'too long' => ['07123456789'],
            'landline 020' => ['0201234567'],
            'wrong prefix' => ['0812345678'],
            'letters' => ['07abcd5678'],
            'wrong country' => ['+255712345678'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedForms')]
    public function it_rejects_non_kenyan_mobiles(?string $raw): void
    {
        $this->assertNull(Phone::normalise($raw));
        $this->assertFalse(Phone::isValid($raw));
        $this->assertNull(Phone::msisdn($raw));
        $this->assertSame([], Phone::lookupForms($raw));
    }

    #[Test]
    public function lookup_forms_returns_both_stored_shapes(): void
    {
        $this->assertSame(['0712345678', '254712345678'], Phone::lookupForms('+254712345678'));
        $this->assertSame(['0712345678', '254712345678'], Phone::lookupForms('0712345678'));
    }
}
