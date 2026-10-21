<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * The check-in QR code as a PNG, for the PDF ticket (TicketPdf). The web
 * ticket draws the same code as SVG (simple-qrcode), but simple-qrcode's
 * PNG backend needs Imagick, which isn't installed — so the matrix comes
 * straight from bacon/bacon-qr-code and is drawn with GD. Two-colour
 * palette, no alpha: dompdf embeds it as is. The content is the raw ticket
 * code, exactly what CheckInService looks up.
 */
class TicketQr
{
    public static function png(string $code, int $modulePx = 10, int $quietZone = 4): string
    {
        $matrix = Encoder::encode($code, ErrorCorrectionLevel::M())->getMatrix();
        $size = ($matrix->getWidth() + 2 * $quietZone) * $modulePx;

        $image = imagecreate($size, $size);
        imagecolorallocate($image, 255, 255, 255); // first colour = background
        $dark = imagecolorallocate($image, 7, 17, 48); // ink #071130

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $quietZone) * $modulePx;
                    $top = ($y + $quietZone) * $modulePx;
                    imagefilledrectangle($image, $left, $top, $left + $modulePx - 1, $top + $modulePx - 1, $dark);
                }
            }
        }

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    public static function dataUri(string $code): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($code));
    }
}
