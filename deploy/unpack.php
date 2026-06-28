<?php
declare(strict_types=1);

// Extractor de payload.zip para despliegue por SFTP (HestiaCP SFTP-only).
// Sube 1 zip grande (fiable) y se extrae aqui con PHP, evitando subir miles de
// archivos pequenos por SFTP. El token __UNPACK_TOKEN__ se sustituye en CI.
// Se autoborra (zip + script) tras extraer.
$expected = '__UNPACK_TOKEN__';
$token = $_GET['token'] ?? '';
header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals($expected, (string) $token)) {
    http_response_code(403);
    exit('forbidden');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive no disponible');
}

$zipPath = __DIR__ . '/payload.zip';
$target = realpath(__DIR__ . '/../../private/api');

if (!is_file($zipPath)) {
    http_response_code(500);
    exit('payload.zip no encontrado en ' . __DIR__);
}
if ($target === false) {
    http_response_code(500);
    exit('destino private/api no encontrado');
}

// 1) Limpiar basura de un intento previo: archivos en target con '\' en el nombre.
$cleaned = 0;
foreach (scandir($target) as $e) {
    if ($e === '.' || $e === '..') {
        continue;
    }
    if (strpos($e, '\\') !== false) {
        $p = $target . '/' . $e;
        if (is_file($p) && @unlink($p)) {
            $cleaned++;
        }
    }
}

// 2) Extraer normalizando separadores '\' -> '/' (bug Compress-Archive PS 5.1).
$za = new ZipArchive();
if ($za->open($zipPath) !== true) {
    http_response_code(500);
    exit('no se pudo abrir el zip');
}
$count = $za->numFiles;
$written = 0;
$errors = 0;
for ($i = 0; $i < $count; $i++) {
    $name = $za->getNameIndex($i);
    if ($name === false) {
        continue;
    }
    $norm = ltrim(str_replace('\\', '/', $name), '/');
    if ($norm === '' || strpos($norm, '..') !== false) {
        continue;
    }
    $dest = $target . '/' . $norm;
    if (substr($norm, -1) === '/') {
        if (!is_dir($dest)) {
            @mkdir($dest, 0775, true);
        }
        continue;
    }
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $stream = $za->getStream($name);
    if ($stream === false) {
        $errors++;
        continue;
    }
    $data = stream_get_contents($stream);
    fclose($stream);
    if (file_put_contents($dest, $data) === false) {
        $errors++;
    } else {
        $written++;
    }
}
$za->close();

$okVendor = is_file($target . '/vendor/vlucas/phpdotenv/src/Dotenv.php');
$okFront = is_file($target . '/public/index.php');

// Limpieza: borrar zip y este script (uso unico).
@unlink($zipPath);
@unlink(__FILE__);

if ($errors > 0 || !$okVendor || !$okFront) {
    http_response_code(500);
}
echo 'entradas=' . $count . "\n";
echo 'escritos=' . $written . "\n";
echo 'errores=' . $errors . "\n";
echo 'limpiados=' . $cleaned . "\n";
echo 'vendor_ok=' . ($okVendor ? 'si' : 'NO') . "\n";
echo 'front_ok=' . ($okFront ? 'si' : 'NO') . "\n";
