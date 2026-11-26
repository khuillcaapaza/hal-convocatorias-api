<?php

declare(strict_types=1);

namespace App\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use JimTools\JwtAuth\Decoder\DecoderInterface;
use JimTools\JwtAuth\Exceptions\AuthorizationException;
use JimTools\JwtAuth\Exceptions\UnexpectedValueException;
use Throwable;

/**
 * Verifica los JWT emitidos por hal-auth-api (copia de archivos-api, ver decisión 0002 de lbyte-contexto).
 *
 * - EdDSA (Ed25519): el token trae `kid` y debe corresponder a una clave pública de
 *   JWT_PUBLIC_KEYS. Solo auth-api, que tiene la clave privada, puede firmarlos.
 * - HS256 con JWT_SECRET: solo mientras JWT_SECRET siga configurado (transición).
 *   Al quitarlo, esta app ya no puede fabricar tokens válidos aunque se filtre su .env.
 *
 * El algoritmo lo decide la clave elegida, no el token: no hay confusión HS256/EdDSA.
 * Por defecto rechaza tokens restringidos (`alcance`, p. ej. MFA pendiente).
 */
final class TokenDecoder implements DecoderInterface
{
    /** @param array<string, string> $publicKeys kid => clave pública Ed25519 en base64url */
    public function __construct(
        private readonly array $publicKeys,
        #[\SensitiveParameter] private readonly string $legacySecret = '',
        private readonly bool $allowScoped = false,
    ) {
    }

    public static function fromEnv(bool $allowScoped = false, array $extraKeys = []): self
    {
        return new self(
            self::parseKeys((string) ($_ENV['JWT_PUBLIC_KEYS'] ?? '')) + $extraKeys,
            (string) ($_ENV['JWT_SECRET'] ?? ''),
            $allowScoped,
        );
    }

    /**
     * "clave1,clave2" (Ed25519 de 32 bytes en base64url o base64) → [kid => clave].
     * Varias claves permiten rotar sin cortar sesiones.
     *
     * @return array<string, string>
     */
    public static function parseKeys(string $value): array
    {
        $keys = [];
        foreach (preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $key) {
            $raw = self::decodeKey($key);
            if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \InvalidArgumentException('JWT_PUBLIC_KEYS contiene una clave Ed25519 inválida');
            }
            $keys[self::kid($raw)] = JWT::urlsafeB64Encode($raw);
        }
        return $keys;
    }

    /** Identificador de la clave: 16 hex del SHA-256 de la clave pública cruda. */
    public static function kid(string $rawPublicKey): string
    {
        return substr(hash('sha256', $rawPublicKey), 0, 16);
    }

    public static function decodeKey(string $key): ?string
    {
        $raw = base64_decode(strtr($key, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }

    public function configured(): bool
    {
        return $this->publicKeys !== [] || $this->legacySecret !== '';
    }

    public function decode(string $jwt): array
    {
        try {
            $header = self::header($jwt);
            $alg = $header['alg'] ?? null;
            if ($alg === 'EdDSA') {
                $kid = $header['kid'] ?? null;
                if (!is_string($kid) || !isset($this->publicKeys[$kid])) {
                    throw new UnexpectedValueException('Clave de firma desconocida');
                }
                $claims = (array) JWT::decode($jwt, new Key($this->publicKeys[$kid], 'EdDSA'));
            } elseif ($alg === 'HS256' && $this->legacySecret !== '') {
                $claims = (array) JWT::decode($jwt, new Key($this->legacySecret, 'HS256'));
            } else {
                throw new UnexpectedValueException('Algoritmo de firma no aceptado');
            }
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnexpectedValueException($e->getMessage(), 0, $e);
        }
        if (!$this->allowScoped && ($claims['alcance'] ?? null) !== null) {
            throw new UnexpectedValueException('Token restringido');
        }
        return $claims;
    }

    /** @return array<string, mixed> */
    private static function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Token mal formado');
        }
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        if (!is_array($header)) {
            throw new UnexpectedValueException('Cabecera inválida');
        }
        return $header;
    }
}
