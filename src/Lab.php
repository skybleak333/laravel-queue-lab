<?php

namespace Lab;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Queue\Events\WorkerStopping;
use Orchestra\Testbench\Foundation\Application;
use PhpAmqpLib\Connection\AMQPLazyConnection;
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
            'connection' => AMQPLazyConnection::class,
            'hosts' => [[
                'host' => getenv('LAB_AMQP_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('LAB_AMQP_PORT') ?: 5672),
                'vhost' => '/',
                'user' => 'guest',
                'password' => 'guest',
            ]],
            'options' => [],
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
}
