<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use PDO;
use PDOException;
use Throwable;

/**
 * PDO-based connection class for the local MySQL database.
 *
 * Responsibilities:
 * - Establishing a PDO connection from the configuration (host, DB, user,
 *   password).
 * - R4.4: connection timeout of 10 seconds; if the connection
 *   cannot be established within this time, access is denied
 *   (DatabaseConnectionException) and an error message is returned that points
 *   to the missing database connection.
 * - R4.2: operations only locally, no external/cloud-based service.
 *
 * The connection is established lazily: only on the first access via
 * {@see Database::pdo()}. This allows configuring the class without an
 * immediate connection attempt.
 */
final class Database
{
    /**
     * Vorgegebener Verbindungs-Timeout in Sekunden (R4.4).
     */
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    private ?PDO $pdo = null;

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     dbname?: string,
     *     user?: string,
     *     password?: string,
     *     charset?: string,
     *     connect_timeout?: int,
     *     dsn?: string
     * } $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Returns the active PDO connection; establishes it on the first call.
     *
     * @throws DatabaseConnectionException if the connection cannot be
     *         established (within the timeout).
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }

        return $this->pdo;
    }

    /**
     * Establishes the PDO connection with a 10 second timeout (requirement
     * 4.4).
     *
     * @throws DatabaseConnectionException
     */
    private function connect(): PDO
    {
        $timeout = (int) ($this->config['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // R4.4: Verbindungs-Timeout begrenzen.
            PDO::ATTR_TIMEOUT            => $timeout,
        ];

        try {
            return new PDO(
                $this->dsn(),
                $this->config['user'] ?? null,
                $this->config['password'] ?? null,
                $options
            );
        } catch (PDOException $exception) {
            // R4.4: deny access and return an error message that
            // points to the missing database connection.
            throw new DatabaseConnectionException(
                'Die Datenbankverbindung konnte nicht hergestellt werden.',
                0,
                $exception
            );
        } catch (Throwable $throwable) {
            throw new DatabaseConnectionException(
                'Die Datenbankverbindung konnte nicht hergestellt werden.',
                0,
                $throwable
            );
        }
    }

    /**
     * Creates the DSN string for the local MySQL connection (R4.2).
     */
    private function dsn(): string
    {
        if (isset($this->config['dsn']) && $this->config['dsn'] !== '') {
            return $this->config['dsn'];
        }

        $host    = $this->config['host'] ?? '127.0.0.1';
        $port    = (int) ($this->config['port'] ?? 3306);
        $dbname  = $this->config['dbname'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $dbname,
            $charset
        );
    }
}
