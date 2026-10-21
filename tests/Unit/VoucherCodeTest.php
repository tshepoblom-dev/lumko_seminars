<?php

namespace Tests\Unit;

use App\Support\VoucherCode;
use Tests\TestCase;

/** Extends the app TestCase: hash() is keyed with config('app.key'). */
class VoucherCodeTest extends TestCase
{
    public function test_generated_codes_are_three_groups_of_four_from_the_unambiguous_alphabet(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $code = VoucherCode::generate();

            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $code);
            $this->assertDoesNotMatchRegularExpression('/[ILOU]/', $code);
        }
    }

    public function test_generated_codes_differ(): void
    {
        $codes = array_map(fn () => VoucherCode::generate(), range(1, 200));

        $this->assertCount(200, array_unique($codes));
    }

    public function test_spacing_case_and_look_alike_letters_normalise_to_the_same_code(): void
    {
        $this->assertSame('AB01CD11EF00', VoucherCode::normalise('ab0I-cd1l-efOo'));
        $this->assertSame('AB01CD11EF00', VoucherCode::normalise(' AB01 CD11 EF00 '));
        $this->assertSame('AB01-CD11-EF00', VoucherCode::format('ab0icd1lefoo'));
    }

    public function test_the_hash_matches_every_spelling_of_a_code_and_only_that_code(): void
    {
        $code = VoucherCode::generate();

        $this->assertSame(VoucherCode::hash($code), VoucherCode::hash(strtolower(str_replace('-', ' ', $code))));
        $this->assertNotSame(VoucherCode::hash($code), VoucherCode::hash(VoucherCode::generate()));
        $this->assertSame(64, strlen(VoucherCode::hash($code)));
    }
}
