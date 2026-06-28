<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 manual para el espacio de nombres App\ → carpeta src/.
 *
 * No depende de Composer: así el refactor MVC no obliga a regenerar ni resubir
 * vendor/ (el servidor es solo-SFTP). App\Controller\AreaController se resuelve
 * a src/Controller/AreaController.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativo = substr($class, strlen($prefix));
    $archivo  = __DIR__ . '/' . str_replace('\\', '/', $relativo) . '.php';

    if (is_file($archivo)) {
        require $archivo;
    }
});
