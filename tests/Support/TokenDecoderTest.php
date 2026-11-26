<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\TokenDecoder;
use Firebase\JWT\JWT;
use JimTools\JwtAuth\Exceptions\AuthorizationException;
use PHPUnit\Framework\TestCase;

/** Verificación de tokens de hal-auth-api: EdDSA con kid y HS256 solo en transición. */
final class TokenDecoderTest extends TestCase
{
    private string $private;
    private string $public;
    private string $secret;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->private = JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey($pair));
        $this->public = JWT::urlsafeB64Encode(sodium_crypto_sign_publickey($pair));
        $this->secret = str_repeat('s', 64);
    }

    private function eddsa(array $claims = [], ?string $kid = null, ?string $private = null): string
    {
        $raw = TokenDecoder::decodeKey($this->public);
        return JWT::encode($claims + ['sub' => 7, 'modulos' => ['convocatorias'], 'exp' => time() + 60], $private ?? $this->private, 'EdDSA', $kid ?? TokenDecoder::kid((string) $raw));
    }

    private function hs(array $claims = []): string
    {
        return JWT::encode($claims + ['sub' => 7, 'modulos' => ['convocatorias'], 'exp' => time() + 60], $this->secret, 'HS256');
    }

    private function rejects(TokenDecoder $decoder, string $token): void
    {
        try {
            $decoder->decode($token);
            self::fail('Debió rechazar el token');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function testAcceptsEdDsaWithKnownKidAndLegacyHs256OnlyWhileSecretExists(): void
    {
        $keys = TokenDecoder::parseKeys($this->public);
        $transition = new TokenDecoder($keys, $this->secret);
        self::assertSame(7, $transition->decode($this->eddsa())['sub']);
        self::assertSame(['convocatorias'], $transition->decode($this->hs())['modulos']);

        // Sin JWT_SECRET la app ya no acepta (ni podría fabricar) tokens HS256.
        $final = new TokenDecoder($keys);
        self::assertSame(7, $final->decode($this->eddsa())['sub']);
        $this->rejects($final, $this->hs());
    }

    public function testRejectsUnknownKeysForgedKidsTamperingAndAlgorithmConfusion(): void
    {
        $decoder = new TokenDecoder(TokenDecoder::parseKeys($this->public), $this->secret);
        $other = JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        // Otra clave privada usando el kid conocido: la firma no valida.
        $this->rejects($decoder, $this->eddsa([], null, $other));
        $this->rejects($decoder, $this->eddsa([], 'desconocido'));
        // Datos alterados.
        [$h, , $s] = explode('.', $this->eddsa());
        $this->rejects($decoder, $h . '.' . JWT::urlsafeB64Encode((string) json_encode(['sub' => 1, 'modulos' => ['todo'], 'exp' => time() + 60])) . '.' . $s);
        // HS256 firmado con la clave pública como secreto (confusión de algoritmo).
        $this->rejects($decoder, JWT::encode(['sub' => 7, 'exp' => time() + 60], $this->public, 'HS256'));
        // alg none, mal formado y expirado.
        $this->rejects($decoder, JWT::urlsafeB64Encode('{"alg":"none","typ":"JWT"}') . '.' . JWT::urlsafeB64Encode('{"sub":7}') . '.');
        $this->rejects($decoder, 'no-es-un-token');
        $this->rejects($decoder, $this->eddsa(['exp' => time() - 10]));
    }

    public function testScopedTokensAreRejectedUnlessAllowed(): void
    {
        $keys = TokenDecoder::parseKeys($this->public);
        $token = $this->eddsa(['alcance' => 'mfa_pendiente', 'modulos' => []]);
        $this->rejects(new TokenDecoder($keys), $token);
        self::assertSame('mfa_pendiente', (new TokenDecoder($keys, '', true))->decode($token)['alcance']);
    }

    public function testParsesSeveralKeysForRotationAndRejectsInvalidOnes(): void
    {
        $second = JWT::urlsafeB64Encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $standard = base64_encode((string) TokenDecoder::decodeKey($this->public));
        self::assertCount(2, TokenDecoder::parseKeys(" $standard , $second "));
        self::assertSame([], TokenDecoder::parseKeys(''));
        $this->expectException(\InvalidArgumentException::class);
        TokenDecoder::parseKeys('abc');
    }
}
