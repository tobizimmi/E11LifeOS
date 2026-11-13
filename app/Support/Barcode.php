<?php

namespace App\Support;

/**
 * Minimal 1D barcode renderer (SVG) for loyalty cards – Code 128 (subset B, universal) and the
 * EAN/UPC family. Enough to reproduce a stored card's barcode so it scans at the till. QR codes
 * are handled by {@see QrCode}; {@see CardCode} dispatches between them.
 *
 * A barcode re-encoded here decodes to the exact same data as the original, so a till scanner
 * reads the same number even if the symbology (e.g. Code 39) was swapped for Code 128.
 */
class Barcode
{
    /**
     * Code 128 symbol patterns (values 0–106): bar/space module widths, starting with a bar.
     */
    private const CODE128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** EAN/UPC digit encodings as 7-module bit strings (1 = bar). */
    private const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const EAN_G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const EAN_R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Which of the left six EAN-13 digits use the L (0) vs G (1) encoding, by first digit. */
    private const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /**
     * An SVG barcode for the value in the given symbology (zxing format name, e.g. CODE_128,
     * EAN_13, UPC_A, EAN_8). Anything that is not a valid EAN/UPC falls back to Code 128.
     */
    public static function svg(string $value, string $format = 'CODE_128', int $height = 90): string
    {
        $value = trim($value);
        $digits = preg_replace('/\D/', '', $value) ?? '';
        $format = strtoupper($format);

        $bits = match (true) {
            $format === 'EAN_13' && strlen($digits) >= 12 => self::ean13(substr(self::withCheckDigit($digits, 12), 0, 13)),
            $format === 'UPC_A' && strlen($digits) >= 11 => self::ean13('0'.substr(self::withCheckDigit('0'.$digits, 12), 1, 12)),
            $format === 'EAN_8' && strlen($digits) >= 7 => self::ean8(substr(self::withCheckDigit($digits, 7), 0, 8)),
            default => self::code128($value !== '' ? $value : ' '),
        };

        return self::render($bits, $height);
    }

    /**
     * Code 128 (subset B) module bits for printable ASCII; non-ASCII is dropped.
     */
    private static function code128(string $value): string
    {
        $chars = array_values(array_filter(str_split($value), fn (string $char) => ord($char) >= 32 && ord($char) <= 126));
        $values = [104]; // Start B.

        foreach ($chars as $char) {
            $values[] = ord($char) - 32;
        }

        $sum = 104;
        foreach ($chars as $position => $char) {
            $sum += ($position + 1) * (ord($char) - 32);
        }

        $values[] = $sum % 103; // Checksum.
        $values[] = 106;        // Stop.

        return self::fromWidths(array_map(fn (int $symbol) => self::CODE128[$symbol], $values));
    }

    /**
     * Turns alternating bar/space width patterns (starting with a bar) into a module bit string.
     *
     * @param  array<int, string>  $patterns
     */
    private static function fromWidths(array $patterns): string
    {
        $bits = '';

        foreach ($patterns as $pattern) {
            foreach (str_split($pattern) as $index => $width) {
                $bits .= str_repeat($index % 2 === 0 ? '1' : '0', (int) $width);
            }
        }

        return $bits;
    }

    private static function ean13(string $digits): string
    {
        $parity = self::EAN_PARITY[(int) $digits[0]];
        $bits = '101'; // Start guard.

        for ($i = 1; $i <= 6; $i++) {
            $digit = (int) $digits[$i];
            $bits .= $parity[$i - 1] === 'L' ? self::EAN_L[$digit] : self::EAN_G[$digit];
        }

        $bits .= '01010'; // Center guard.

        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::EAN_R[(int) $digits[$i]];
        }

        return $bits.'101'; // End guard.
    }

    private static function ean8(string $digits): string
    {
        $bits = '101';

        for ($i = 0; $i < 4; $i++) {
            $bits .= self::EAN_L[(int) $digits[$i]];
        }

        $bits .= '01010';

        for ($i = 4; $i < 8; $i++) {
            $bits .= self::EAN_R[(int) $digits[$i]];
        }

        return $bits.'101';
    }

    /**
     * Appends the EAN/UPC check digit when only the first ($length) digits are given.
     */
    private static function withCheckDigit(string $digits, int $length): string
    {
        if (strlen($digits) > $length) {
            $digits = substr($digits, 0, $length);
        }

        if (strlen($digits) < $length) {
            return str_pad($digits, $length + 1, '0'); // Too short – pad, renders but won't be meaningful.
        }

        $sum = 0;
        foreach (str_split(strrev($digits)) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return $digits.((10 - $sum % 10) % 10);
    }

    /**
     * Renders a module bit string as an SVG (1 = bar), with a 10-module quiet zone each side.
     */
    private static function render(string $bits, int $height): string
    {
        $quiet = 10;
        $width = strlen($bits) + 2 * $quiet;
        $path = '';

        foreach (str_split($bits) as $index => $bit) {
            if ($bit === '1') {
                $path .= 'M'.($index + $quiet).' 0h1v'.$height.'h-1z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.$height.'" width="100%" height="'.$height.'" preserveAspectRatio="none" shape-rendering="crispEdges" role="img">'
            .'<rect width="100%" height="100%" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
    }
}
