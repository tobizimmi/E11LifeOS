<?php

// One-off helper to generate simple placeholder PWA icons (solid background + initials).
// Run: php scripts/generate_pwa_icons.php
// Replace public/icons/*.png with real branded artwork before shipping to real users.

$sizes = [192, 512];
$bg = [79, 70, 229]; // indigo-600, matches Breeze/Tailwind primary color
$outDir = __DIR__.'/../public/icons';

if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

foreach ($sizes as $size) {
    $image = imagecreatetruecolor($size, $size);
    $bgColor = imagecolorallocate($image, $bg[0], $bg[1], $bg[2]);
    imagefill($image, 0, 0, $bgColor);

    $text = 'E11';

    // GD's built-in bitmap font doesn't scale, so draw it small and resample up.
    $small = imagecreatetruecolor(100, 40);
    $smallBg = imagecolorallocate($small, $bg[0], $bg[1], $bg[2]);
    imagefill($small, 0, 0, $smallBg);
    $smallWhite = imagecolorallocate($small, 255, 255, 255);
    imagestring($small, 5, 15, 12, $text, $smallWhite);

    imagecopyresampled($image, $small, 0, 0, 0, 0, $size, $size, 100, 40);

    imagepng($image, "{$outDir}/icon-{$size}.png");

    echo "Generated icon-{$size}.png\n";
}
