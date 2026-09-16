<?php
declare(strict_types=1);

/**
 * Generates neutral placeholder portraits for the demo candidates so the photo
 * pipeline can be seen working. These are plainly abstract images — initials on
 * a coloured field — not pictures of real or invented people.
 *
 *   php bin/demo_photos.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/mail.php';
require dirname(__DIR__) . '/src/audit.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/elections.php';

$palette = [
    [0x1f, 0x3b, 0x73], [0x1c, 0x6b, 0x45], [0xa6, 0x7c, 0x1e],
    [0x6b, 0x2d, 0x5c], [0x2b, 0x5f, 0x74], [0x8a, 0x3d, 0x2a],
    [0x3f, 0x4a, 0x6b], [0x5a, 0x6b, 0x2d],
];

$tmpDir = sys_get_temp_dir();
$made   = 0;

foreach (db_all('SELECT id, full_name, photo_path FROM candidates ORDER BY id') as $i => $c) {
    if ($c['photo_path']) {
        continue;
    }

    $size = 640;
    $im   = imagecreatetruecolor($size, $size);
    [$r, $g, $b] = $palette[$i % count($palette)];

    // A soft vertical gradient so the cards do not read as flat blocks.
    for ($y = 0; $y < $size; $y++) {
        $f    = 1 - ($y / $size) * 0.45;
        $line = imagecolorallocate($im, (int)($r * $f + 40), (int)($g * $f + 40), (int)($b * $f + 40));
        imageline($im, 0, $y, $size, $y, $line);
    }

    // Initials.
    $parts = preg_split('/\s+/u', trim((string)$c['full_name'])) ?: [];
    $ini   = mb_substr($parts[0] ?? '?', 0, 1, 'UTF-8')
           . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '');
    $ini   = mb_strtoupper($ini, 'UTF-8');

    $white = imagecolorallocatealpha($im, 255, 255, 255, 25);
    $font  = 5;
    $scale = 9;
    $tw    = imagefontwidth($font) * mb_strlen($ini, 'UTF-8');
    $th    = imagefontheight($font);

    $label = imagecreatetruecolor($tw, $th);
    imagesavealpha($label, true);
    imagefill($label, 0, 0, imagecolorallocatealpha($label, 0, 0, 0, 127));
    imagestring($label, $font, 0, 0, $ini, imagecolorallocate($label, 255, 255, 255));

    $lw = $tw * $scale;
    $lh = $th * $scale;
    imagecopyresampled($im, $label, (int)(($size - $lw) / 2), (int)(($size - $lh) / 2), 0, 0,
                       $lw, $lh, $tw, $th);
    imagedestroy($label);
    unset($white);

    $tmp = $tmpDir . '/demo_photo_' . $c['id'] . '.png';
    imagepng($im, $tmp);
    imagedestroy($im);

    // Deliberately pushed through the real upload path, so what ends up on disk
    // is what a real upload would produce.
    [$ok, $err, $stored] = store_candidate_photo([
        'error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'name' => 'demo.png',
    ]);
    unlink($tmp);

    if (!$ok) {
        echo "  failed for {$c['full_name']}: {$err}\n";
        continue;
    }

    db_run('UPDATE candidates SET photo_path = ? WHERE id = ?', [$stored, (int)$c['id']]);
    $made++;
}

echo $made . " placeholder portraits generated and stored through the upload pipeline.\n";
