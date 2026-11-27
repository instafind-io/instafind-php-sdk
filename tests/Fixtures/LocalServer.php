<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Fixtures;

/**
 * PHP's built-in web server with tests/Fixtures/server.php, for transport tests.
 */
final class LocalServer
{
    /** @var resource|null */
    private static $process = null;

    private static string $url = '';

    public static function url(): string
    {
        if (self::$process !== null) {
            return self::$url;
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('No free port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            // Several workers, so parallel requests are answered in parallel.
            ['PHP_CLI_SERVER_WORKERS' => '6'] + getenv(),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the built-in server.');
        }
        self::$process = $process;
        self::$url = 'http://127.0.0.1:' . $port;
        register_shutdown_function(static function (): void {
            if (self::$process !== null) {
                proc_terminate(self::$process);
            }
        });

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return self::$url;
            }
            usleep(50_000);
        }

        throw new \RuntimeException('The built-in server did not start.');
    }
}
