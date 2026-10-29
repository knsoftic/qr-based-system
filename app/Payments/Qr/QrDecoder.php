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
        $damaged = false;

        foreach (self::ATTEMPTS as $attempt) {
            try {
                $options = new QROptions(['readerUseImagickIfAvailable' => false] + $attempt);
                $data = (string) (new QRCode($options))->readFromFile($path);

                if ($data !== '') {
                    return $data;
                }
            } catch (Throwable $e) {
                $lastError = $e;
                // The QR was located, but error correction could not repair it.
                $damaged = $damaged || basename($e->getFile()) === 'ReedSolomonDecoder.php';
            }
        }

        throw new QrDecodeException(
            $damaged
                ? 'A QR code was found, but part of it cannot be read: usually a logo in the middle covers more than the QR can repair. '
                    .'Use "Paste QR text" (scan it with your phone camera and copy the text) or "Add by IBAN" instead.'
                : 'No readable QR code was found in the image. Upload a clear, uncropped image of the QR, or use "Paste QR text" / "Add by IBAN".',
            previous: $lastError,
        );
    }
}
