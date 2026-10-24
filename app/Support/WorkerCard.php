<?php

namespace App\Support;

/**
 * The QR code on a worker's card (App\Support\Qr).
 *
 * It holds the worker number and nothing else (ADR 0011), the same as Boord's badges hold the employee number,
 * so the cards Bekfontein already has keep scanning once its workers are registered here by those numbers.
 */
class WorkerCard
{
    public static function qrSvg(string $number): string
    {
        return Qr::svg($number);
    }
}
