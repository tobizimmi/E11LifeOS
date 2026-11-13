<?php

namespace App\Support;

/**
 * Renders a loyalty card's stored code in its original symbology: a QR code via {@see QrCode},
 * the EAN/UPC family and every other 1D barcode via {@see Barcode}. Used both for display and so
 * the reproduced code scans at the till.
 */
class CardCode
{
    /** Code formats the app offers for manual entry (zxing format names). */
    public const FORMATS = ['QR_CODE', 'EAN_13', 'EAN_8', 'UPC_A', 'CODE_128', 'CODE_39'];

    /** Formats drawn as a square 2D code rather than a 1D barcode. */
    private const TWO_DIMENSIONAL = ['QR_CODE', 'AZTEC', 'DATA_MATRIX', 'PDF_417', 'MAXICODE'];

    public static function isTwoDimensional(string $format): bool
    {
        return in_array(strtoupper($format), self::TWO_DIMENSIONAL, true);
    }

    /**
     * An SVG of the card code. 2D formats render as a QR code; everything else as a 1D barcode.
     */
    public static function svg(string $value, string $format = 'QR_CODE'): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (self::isTwoDimensional($format)) {
            try {
                return QrCode::svg($value);
            } catch (\Throwable) {
                // Value too long for the QR encoder – fall back to a barcode so something shows.
                return Barcode::svg($value, 'CODE_128');
            }
        }

        return Barcode::svg($value, $format);
    }

    /**
     * A human label for a format name.
     */
    public static function label(string $format): string
    {
        return match (strtoupper($format)) {
            'QR_CODE' => 'QR-Code',
            'EAN_13' => 'EAN-13',
            'EAN_8' => 'EAN-8',
            'UPC_A' => 'UPC-A',
            'UPC_E' => 'UPC-E',
            'CODE_128' => 'Code 128',
            'CODE_39' => 'Code 39',
            'ITF' => 'ITF',
            'CODABAR' => 'Codabar',
            'DATA_MATRIX' => 'Data Matrix',
            'AZTEC' => 'Aztec',
            'PDF_417' => 'PDF417',
            default => $format,
        };
    }
}
