<?php

namespace App\Payments\Qr;

use App\Payments\Exceptions\QrDecodeException;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Reads the text payload out of an uploaded QR image (GD based).
 */
class QrDecoder
{
    /**
     * Option sets tried in order: plain first, then grayscale + contrast,
     * which helps with phone screenshots and photos.
     */
    private const ATTEMPTS = [
        ['readerGrayscale' => false, 'readerIncreaseContrast' => false],
        ['readerGrayscale' => true, 'readerIncreaseContrast' => true],
    ];

    public function decodeFile(string $path): string
    {
        if (! is_file($path)) {
            throw new QrDecodeException('Uploaded file not found.');
        }

        $lastError = null;

        foreach (self::ATTEMPTS as $attempt) {
            try {
                $options = new QROptions(['readerUseImagickIfAvailable' => false] + $attempt);
                $data = (string) (new QRCode($options))->readFromFile($path);

                if ($data !== '') {
                    return $data;
                }
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        throw new QrDecodeException(
            'No readable QR code was found in the image. Upload a clear, uncropped image of the QR.',
            previous: $lastError,
        );
    }
}
