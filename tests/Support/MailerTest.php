<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Mailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Tests\TestCase;

final class MailerTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
    }

    private function configurarSmtp(string $secure = 'ssl'): void
    {
        $_ENV['SMTP_HOST'] = 'smtp.test';
        $_ENV['SMTP_USER'] = 'user@test';
        $_ENV['SMTP_PASS'] = 'secret';
        $_ENV['SMTP_SECURE'] = $secure;
        unset($_ENV['SMTP_PORT'], $_ENV['SMTP_FROM'], $_ENV['SMTP_FROM_NAME']);
    }

    /** Construye un Mailer cuyo PHPMailer interno es el doble proporcionado. */
    private function mailerCon(PHPMailer $doble): Mailer
    {
        return new class($doble) extends Mailer {
            public function __construct(private PHPMailer $doble)
            {
            }

            protected function nuevoMailer(): PHPMailer
            {
                return $this->doble;
            }
        };
    }

    public function testLanzaSiSmtpNoConfigurado(): void
    {
        $_ENV['SMTP_HOST'] = '';
        $_ENV['SMTP_USER'] = '';
        $_ENV['SMTP_PASS'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP no configurado');

        (new Mailer())->enviar('a@b.test', 'Ana', 'Asunto', '<p>hola</p>');
    }

    public function testEnviaCorreoConSecureSsl(): void
    {
        $this->configurarSmtp('ssl');

        $doble = $this->createMock(PHPMailer::class);
        $doble->expects($this->once())->method('send')->willReturn(true);

        $this->mailerCon($doble)->enviar('a@b.test', 'Ana', 'Asunto', '<p>hola</p>', 'hola');

        $this->assertSame('smtp.test', $doble->Host);
        $this->assertSame(465, $doble->Port);
        $this->assertSame(PHPMailer::ENCRYPTION_SMTPS, $doble->SMTPSecure);
    }

    public function testEnviaCorreoConSecureTlsYAltBodyDesdeHtml(): void
    {
        $this->configurarSmtp('tls');

        $doble = $this->createMock(PHPMailer::class);
        $doble->method('send')->willReturn(true);

        // Sin texto: AltBody se deriva del HTML (strip_tags).
        $this->mailerCon($doble)->enviar('a@b.test', 'Ana', 'Asunto', '<p>hola</p>');

        $this->assertSame(587, $doble->Port);
        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $doble->SMTPSecure);
        $this->assertSame('hola', $doble->AltBody);
    }

    public function testConvierteExcepcionDePhpmailerEnRuntime(): void
    {
        $this->configurarSmtp('ssl');

        $doble = $this->createMock(PHPMailer::class);
        $doble->method('send')->willThrowException(new PHPMailerException('smtp caído'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo enviar el correo');

        $this->mailerCon($doble)->enviar('a@b.test', 'Ana', 'Asunto', '<p>hola</p>');
    }

    public function testNuevoMailerCreaInstanciaDePhpmailer(): void
    {
        $mailer = new class extends Mailer {
            public function exponerNuevoMailer(): PHPMailer
            {
                return $this->nuevoMailer();
            }
        };

        $this->assertInstanceOf(PHPMailer::class, $mailer->exponerNuevoMailer());
    }
}
