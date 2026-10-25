<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR SVG con Bacon (el mismo motor que 2FA). Sin APIs externas.
 */
class QrCodeService
{
    public function svg(string $payload, int $size = 320): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd
        );

        return (new Writer($renderer))->writeString($payload);
    }
}
