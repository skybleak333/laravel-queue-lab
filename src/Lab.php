<?php

namespace Lab;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Queue\Events\WorkerStopping;
use Orchestra\Testbench\Foundation\Application;
use Throwable;
use UnitEnum;
use VladimirYuldashev\LaravelQueueRabbitMQ\LaravelQueueRabbitMQServiceProvider;

/**
 * A minimal Laravel application (Orchestra Testbench) with the RabbitMQ queue driver.
 * It writes the events the experiments look at to the EventLog.
 */
final class Lab
{
    public static function app(): LaravelApplication
    {
        $app = Application::create(
            options: ['extra' => ['providers' => [LaravelQueueRabbitMQServiceProvider::class]]],
        );

        // Testbench calls its resolving callback before the configuration is loaded, so the
        // values are set here. Queue connections are resolved lazily and pick them up.
        $config = $app['config'];
        $config->set('queue.default', 'rabbitmq');
        $config->set('queue.failed.driver', 'null');
        $config->set('queue.connections.rabbitmq', [
            'driver' => 'rabbitmq',
            'queue' => 'default',
            // The package's default connection path. The deprecated AMQPLazyConnection::class path
            // reads 'read_write_timeout' and ignores 'read_timeout' and 'write_timeout'.
            'connection' => 'default',
            'hosts' => [[
                'host' => getenv('LAB_AMQP_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('LAB_AMQP_PORT') ?: 5672),
                'vhost' => '/',
                'user' => 'guest',
                'password' => 'guest',
            ]],
            'options' => self::connectionOptions(),
            'worker' => 'default',
        ]);
        $config->set('cache.default', 'array');
        $config->set('logging.default', 'null');

        $handler = $app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (Throwable $e): void {
                EventLog::write('exception', ['class' => $e::class, 'code' => $e->getCode(), 'message' => $e->getMessage()]);
            });
        }

        $app['events']->listen(WorkerStopping::class, static function (WorkerStopping $event): void {
            $reason = property_exists($event, 'reason') ? $event->reason : null;

            EventLog::write('worker.stopping', [
                'status' => $event->status,
                'reason' => $reason instanceof UnitEnum ? $reason->name : $reason,
            ]);
        });

        return $app;
    }

    /**
     * Connection options from the environment, so that every worker of an experiment can
     * get its own: LAB_HEARTBEAT (seconds), LAB_IO_TIMEOUT (socket read and write timeout)
     * and LAB_RPC_TIMEOUT (channel_rpc_timeout). Unset or not a number means the package default.
     *
     * @return array<string, int|float>
     */
    private static function connectionOptions(): array
    {
        $options = [];

        if (is_numeric($heartbeat = getenv('LAB_HEARTBEAT'))) {
            $options['heartbeat'] = (int) $heartbeat;
        }

        if (is_numeric($timeout = getenv('LAB_IO_TIMEOUT'))) {
            $options['read_timeout'] = (float) $timeout;
            $options['write_timeout'] = (float) $timeout;
        }

        if (is_numeric($rpcTimeout = getenv('LAB_RPC_TIMEOUT'))) {
            $options['channel_rpc_timeout'] = (float) $rpcTimeout;
        }

        return $options;
    }
}
