<?php

namespace App\Payments\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrRenderer
{
    /**
     * Inline SVG markup for the checkout page.
     */
    public function svg(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'drawLightModules' => false,
            'connectPaths' => true,
        ]);

        return (new QRCode($options))->render($payload);
    }

    /**
     * Raw PNG bytes (used for samples and tests).
     */
    public function png(string $payload, int $scale = 8): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
