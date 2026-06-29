<?php

declare(strict_types=1);

/**
 * Siembra (migra) las convocatorias históricas del sitio estático hacia la BD.
 *
 * Lee scripts/data/convocatorias-seed.json e inserta cada convocatoria y sus
 * archivos (metadatos) en las tablas `convocatorias` y `convocatoria_archivos`.
 * Es idempotente: omite las convocatorias cuyo slug ya exista (usa --replace
 * para borrarlas y volver a insertarlas).
 *
 * El tamaño de cada archivo se calcula leyendo el binario desde un directorio
 * de origen (opcional): cada slug debe ser una subcarpeta con sus archivos.
 * Si no se indica origen o el archivo no existe, el tamaño se guarda como 0.
 *
 * Uso:
 *   php scripts/seed-convocatorias.php [--replace] [--source <dir>]
 *
 * Ejemplo (local, tomando los PDFs ya publicados del sitio):
 *   php scripts/seed-convocatorias.php --source "C:/Users/ruben/proyectos-hal/hal-site/public/convocatorias"
 */

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
}

// ── Argumentos ────────────────────────────────────────────────────────
$replace = false;
$source  = null;
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--replace') {
        $replace = true;
    } elseif ($arg === '--source') {
        $source = rtrim((string) ($argv[++$i] ?? ''), "/\\");
    }
}

// ── Datos ─────────────────────────────────────────────────────────────
$jsonPath = __DIR__ . '/data/convocatorias-seed.json';
$raw      = @file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "No se pudo leer {$jsonPath}\n");
    exit(1);
}
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['convocatorias']) || !is_array($data['convocatorias'])) {
    fwrite(STDERR, "JSON inválido en {$jsonPath}\n");
    exit(1);
}

/** Construye una etiqueta legible a partir del nombre de archivo. */
function labelFromFilename(string $fileName): string
{
    $base = preg_replace('/\.[^.]+$/', '', $fileName) ?? $fileName;
    if (preg_match('/^(SKM[_-]|img\d|DOC-?\d|CamScanner|WhatsApp|SCAN|ilovepdf|\d{6,})/i', $base)) {
        return 'Documento adjunto';
    }
    $label = preg_replace('/[-_]+/', ' ', $base) ?? $base;
    $label = preg_replace('/\s*\(\d+\)\s*$/', '', $label) ?? $label;
    $label = preg_replace('/\s+/', ' ', $label) ?? $label;

    return trim($label);
}

/** @var PDO $pdo */
$pdo = require __DIR__ . '/../src/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$insConv = $pdo->prepare(
    'INSERT INTO convocatorias
        (slug, titulo, area, fecha_publicacion, estado, descripcion, cuerpo, publicado)
     VALUES
        (:slug, :titulo, :area, :fecha_publicacion, :estado, :descripcion, :cuerpo, 1)'
);

$insArch = $pdo->prepare(
    'INSERT INTO convocatoria_archivos
        (convocatoria_id, etiqueta, nombre_archivo, ext, tamano, orden)
     VALUES
        (:convocatoria_id, :etiqueta, :nombre_archivo, :ext, :tamano, :orden)'
);

$selId   = $pdo->prepare('SELECT id FROM convocatorias WHERE slug = ? LIMIT 1');
$delConv = $pdo->prepare('DELETE FROM convocatorias WHERE slug = ?');

$creadas = 0;
$omitidas = 0;
$archivosTotal = 0;
$sinBinario = 0;

foreach ($data['convocatorias'] as $c) {
    $slug = (string) ($c['slug'] ?? '');
    if ($slug === '') {
        continue;
    }

    $selId->execute([$slug]);
    $existe = $selId->fetchColumn();

    if ($existe !== false) {
        if (!$replace) {
            echo "= {$slug}: ya existe, se omite.\n";
            $omitidas++;
            continue;
        }
        $delConv->execute([$slug]); // CASCADE borra sus archivos
    }

    $pdo->beginTransaction();
    try {
        $insConv->execute([
            'slug'              => $slug,
            'titulo'            => (string) $c['titulo'],
            'area'              => (string) $c['area'],
            'fecha_publicacion' => (string) $c['fecha_publicacion'],
            'estado'            => (string) $c['estado'],
            'descripcion'       => (string) $c['descripcion'],
            'cuerpo'            => '',
        ]);
        $convId = (int) $pdo->lastInsertId();

        $orden = 1;
        foreach (($c['files'] ?? []) as $nombre) {
            $nombre = (string) $nombre;
            $ext    = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

            $tamano = 0;
            if ($source !== null) {
                $ruta = $source . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . $nombre;
                if (is_file($ruta)) {
                    $tamano = (int) filesize($ruta);
                } else {
                    $sinBinario++;
                }
            }

            $insArch->execute([
                'convocatoria_id' => $convId,
                'etiqueta'        => labelFromFilename($nombre),
                'nombre_archivo'  => $nombre,
                'ext'             => $ext,
                'tamano'          => $tamano,
                'orden'           => $orden++,
            ]);
            $archivosTotal++;
        }

        $pdo->commit();
        $nfiles = count($c['files'] ?? []);
        echo "+ {$slug}: insertada con {$nfiles} archivo(s).\n";
        $creadas++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "! {$slug}: error — {$e->getMessage()}\n");
    }
}

echo "\nResumen: {$creadas} creada(s), {$omitidas} omitida(s), {$archivosTotal} archivo(s) registrado(s).\n";
if ($source === null) {
    echo "Nota: sin --source, los tamaños quedaron en 0 (cosmético).\n";
} elseif ($sinBinario > 0) {
    echo "Aviso: {$sinBinario} archivo(s) no se encontraron en el origen; su tamaño quedó en 0.\n";
}
