<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The QR code on a worker's card, drawn on the server as SVG: vector, so it prints hard-edged at the printer's
 * own resolution, and no QR library to vendor into the browser.
 *
 * It holds the worker number and nothing else (ADR 0011), the same as Boord's badges hold the employee number,
 * so the cards Bekfontein already has keep scanning once its workers are registered here by those numbers.
 */
class WorkerCard
{
    public static function qrSvg(string $number): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            // H (30% recoverable) rather than M: cards live in pockets and get rained on, scratched and
            // thumbed. Numbers are short, so even at H it is a small code with large modules.
            'eccLevel' => EccLevel::H,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'connectPaths' => true,
        ]);

        return (new QRCode($options))->render($number);
    }
}
