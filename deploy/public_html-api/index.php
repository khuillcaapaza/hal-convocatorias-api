<?php

declare(strict_types=1);

// Front controller de PRODUCCIÓN (vive en public_html/api/).
// El código real (vendor, src, .env) está en ../../private/api, fuera del web root.
// HestiaCP incluye la carpeta "private" del dominio en open_basedir por defecto.
require __DIR__ . '/../../private/api/public/index.php';
