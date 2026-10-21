<?php

namespace Tests\Unit;

use App\Support\TicketQr;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class TicketQrTest extends TestCase
{
    public function test_a_ticket_code_becomes_a_square_two_colour_png_with_a_quiet_zone(): void
    {
        // A 40-character code at level M is QR version 3: 29 modules, plus a
        // 4-module quiet zone each side, at 10px a module.
        $png = TicketQr::png(Str::random(40));
        $info = getimagesizefromstring($png);

        $this->assertSame('image/png', $info['mime']);
        $this->assertSame((29 + 8) * 10, $info[0]);
        $this->assertSame($info[0], $info[1]);

        $image = imagecreatefromstring($png);
        $this->assertFalse(imageistruecolor($image)); // palette: no alpha for dompdf to split
        $this->assertSame(2, imagecolorstotal($image));
        // The quiet zone is white; a finder pattern's corner is ink.
        $this->assertSame([255, 255, 255], $this->rgb($image, 5, 5));
        $this->assertSame([7, 17, 48], $this->rgb($image, 45, 45));
    }

    public function test_the_data_uri_wraps_the_same_png(): void
    {
        $this->assertSame('data:image/png;base64,'.base64_encode(TicketQr::png('E2ETEST1')), TicketQr::dataUri('E2ETEST1'));
    }

    private function rgb($image, int $x, int $y): array
    {
        $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$c['red'], $c['green'], $c['blue']];
    }
}
