<?php
declare(strict_types=1);

function image_dhash(string $path, string $mime): string {
    if (!extension_loaded('gd')) throw new RuntimeException('PHP GD is disabled. Enable extension=gd in C:\\xampp\\php\\php.ini, then restart Apache.');
    $image = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false
    };
    if (!$image) throw new RuntimeException('This image could not be decoded. Use a valid JPG, PNG or WebP photo.');
    $small = imagecreatetruecolor(9,8);
    imagecopyresampled($small, $image, 0,0,0,0,9,8,imagesx($image),imagesy($image));
    imagedestroy($image);
    $bits='';
    for ($y=0; $y<8; $y++) {
        for ($x=0; $x<8; $x++) {
            $a=imagecolorat($small,$x,$y); $b=imagecolorat($small,$x+1,$y);
            $la=((($a>>16)&255)*299+(($a>>8)&255)*587+($a&255)*114);
            $lb=((($b>>16)&255)*299+((($b>>8)&255))*587+($b&255)*114);
            $bits .= $la > $lb ? '1' : '0';
        }
    }
    imagedestroy($small);
    return $bits;
}
function image_similarity(string $a,string $b): float {
    if (strlen($a)!==64 || strlen($b)!==64) return 0;
    $different=0; for($i=0;$i<64;$i++) if($a[$i]!==$b[$i]) $different++;
    return round(100*(64-$different)/64,2);
}
