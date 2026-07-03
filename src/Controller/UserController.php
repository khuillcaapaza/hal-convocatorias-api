<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\UsuarioModel;
use App\Support\Controller;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CRUD de usuarios. Solo admin puede gestionar usuarios (crear, actualizar, listar, eliminar).
 * Todos los usuarios autenticados pueden cambiar su propia contraseña.
 */
final class UserController extends Controller
{
    private UsuarioModel $usuarios;

    public function __construct(?UsuarioModel $usuarios = null)
    {
        $this->usuarios = $usuarios ?? new UsuarioModel();
    }

    /**
     * GET /admin/users — Lista todos los usuarios (solo admin).
     */
    public function adminIndex(Request $request, Response $response): Response
    {
        if ($this->userRole($request) !== 'admin') {
            return $this->json($response, ['error' => 'Acceso denegado'], 403);
        }

        $q = $request->getQueryParams();
        $page = (int) ($q['page'] ?? 1);
        $per_page = (int) ($q['per_page'] ?? 20);

        $resultado = $this->usuarios->paginar($page, $per_page);

        return $this->json($response, [
            'usuarios' => $resultado['items'],
            'meta'     => [
                'total'       => $resultado['total'],
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => $resultado['total_pages'],
            ],
        ]);
    }

    /**
     * POST /admin/users — Crear usuario (solo admin).
     */
    public function adminStore(Request $request, Response $response): Response
    {
        if ($this->userRole($request) !== 'admin') {
            return $this->json($response, ['error' => 'Acceso denegado'], 403);
        }

        $data = $this->parseJson($request);

        if (empty($data['usuario']) || empty($data['email']) || empty($data['password']) || empty($data['nombre'])) {
            return $this->json($response, ['error' => 'Campos requeridos: usuario, email, password, nombre'], 400);
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['error' => 'Email inválido'], 400);
        }

        try {
            $id = $this->usuarios->crear([
                'usuario' => $data['usuario'],
                'email'   => strtolower($data['email']),
                'nombre'  => $data['nombre'],
                'rol'     => $data['rol'] ?? 'usuario',
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ]);

            return $this->json($response, [
                'message' => 'Usuario creado exitosamente',
                'id'      => $id,
            ], 201);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /**
     * PUT /admin/users/{id} — Actualizar usuario (solo admin).
     */
    public function adminUpdate(Request $request, Response $response, array $args): Response
    {
        if ($this->userRole($request) !== 'admin') {
            return $this->json($response, ['error' => 'Acceso denegado'], 403);
        }

        $id = (int) $args['id'];
        $data = $this->parseJson($request);

        if (empty($data)) {
            return $this->json($response, ['error' => 'No hay datos para actualizar'], 400);
        }

        try {
            $this->usuarios->actualizar($id, $data);
            return $this->json($response, ['message' => 'Usuario actualizado exitosamente']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /**
     * DELETE /admin/users/{id} — Eliminar usuario (solo admin).
     */
    public function adminDelete(Request $request, Response $response, array $args): Response
    {
        if ($this->userRole($request) !== 'admin') {
            return $this->json($response, ['error' => 'Acceso denegado'], 403);
        }

        $id = (int) $args['id'];

        // Evitar que se elimine el propio admin
        if ($id === $this->userId($request)) {
            return $this->json($response, ['error' => 'No puedes eliminar tu propia cuenta'], 400);
        }

        try {
            $this->usuarios->eliminar($id);
            return $this->json($response, ['message' => 'Usuario eliminado exitosamente']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /admin/users/{id}/reset-password — Resetear contraseña (solo admin).
     */
    public function adminResetPassword(Request $request, Response $response, array $args): Response
    {
        if ($this->userRole($request) !== 'admin') {
            return $this->json($response, ['error' => 'Acceso denegado'], 403);
        }

        $id = (int) $args['id'];
        $data = $this->parseJson($request);

        if (empty($data['password'])) {
            return $this->json($response, ['error' => 'Se requiere nueva contraseña'], 400);
        }

        try {
            $this->usuarios->actualizar($id, [
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ]);
            return $this->json($response, ['message' => 'Contraseña reseteada exitosamente']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /users/change-password — Cambiar contraseña propia (todos los usuarios autenticados).
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $data = $this->parseJson($request);

        if (empty($data['current_password']) || empty($data['new_password'])) {
            return $this->json($response, ['error' => 'Se requieren contraseña actual y nueva'], 400);
        }

        $userId = $this->userId($request);
        if (!$userId) {
            return $this->json($response, ['error' => 'Usuario no autenticado'], 401);
        }

        try {
            $usuario = $this->usuarios->porId($userId);
            if (!$usuario) {
                return $this->json($response, ['error' => 'Usuario no encontrado'], 404);
            }

            if (!password_verify($data['current_password'], $usuario['password_hash'])) {
                return $this->json($response, ['error' => 'Contraseña actual incorrecta'], 401);
            }

            $this->usuarios->actualizar($userId, [
                'password_hash' => password_hash($data['new_password'], PASSWORD_DEFAULT),
            ]);

            return $this->json($response, ['message' => 'Contraseña cambiada exitosamente']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /users/me — Obtener datos del usuario actual.
     */
    public function me(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        if (!$userId) {
            return $this->json($response, ['error' => 'Usuario no autenticado'], 401);
        }

        $usuario = $this->usuarios->porId($userId);
        if (!$usuario) {
            return $this->json($response, ['error' => 'Usuario no encontrado'], 404);
        }

        // Remover datos sensibles
        unset($usuario['password_hash']);

        return $this->json($response, ['usuario' => $usuario]);
    }
}
