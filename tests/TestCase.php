<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Base de tests: helpers para construir peticiones/respuestas PSR-7 y dobles de
 * PDO/PDOStatement sin necesidad de una base de datos real.
 */
abstract class TestCase extends BaseTestCase
{
    protected function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @param array<string,mixed>  $parsedBody
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers
     * @param array<string,mixed>  $attributes
     */
    protected function request(
        string $method = 'GET',
        ?array $parsedBody = null,
        array $query = [],
        array $headers = [],
        array $attributes = []
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://api.test/')
            ->withParsedBody($parsedBody)
            ->withQueryParams($query);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    /** Decodifica el cuerpo JSON de una respuesta. */
    protected function jsonBody(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * Crea un doble de PDOStatement.
     *
     * @param array{execute?:bool,fetch?:mixed,fetchAll?:array,fetchColumn?:mixed,rowCount?:int} $opts
     */
    protected function stmt(array $opts = []): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn($opts['execute'] ?? true);
        $stmt->method('fetch')->willReturn($opts['fetch'] ?? false);
        $stmt->method('fetchAll')->willReturn($opts['fetchAll'] ?? []);
        $stmt->method('fetchColumn')->willReturn($opts['fetchColumn'] ?? false);
        $stmt->method('rowCount')->willReturn($opts['rowCount'] ?? 0);

        return $stmt;
    }

    /**
     * Crea un doble de PDO que devuelve los statements indicados, en orden, para
     * prepare() y query(). lastInsertId() devuelve el valor dado.
     *
     * @param list<PDOStatement> $prepare
     * @param list<PDOStatement> $query
     */
    protected function pdo(array $prepare = [], array $query = [], string $lastInsertId = '0'): PDO
    {
        $pdo = $this->createMock(PDO::class);

        $prepareQueue = $prepare;
        $pdo->method('prepare')->willReturnCallback(
            static function () use (&$prepareQueue): PDOStatement {
                return array_shift($prepareQueue);
            }
        );

        $queryQueue = $query;
        $pdo->method('query')->willReturnCallback(
            static function () use (&$queryQueue): PDOStatement {
                return array_shift($queryQueue);
            }
        );

        $pdo->method('lastInsertId')->willReturn($lastInsertId);

        return $pdo;
    }
}
