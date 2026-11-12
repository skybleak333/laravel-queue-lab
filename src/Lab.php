<?php

namespace Lab;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Queue\Events\JobDebounced;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\WorkerPausing;
use Illuminate\Queue\Events\WorkerQueuePaused;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Orchestra\Testbench\Foundation\Application;
use RuntimeException;
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
            options: ['extra' => ['providers' => [LaravelQueueRabbitMQServiceProvider::class, AiServiceProvider::class]]],
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
        $config->set('logging.default', 'null');

        // LAB_DATABASE: an SQLite file for the database queue driver, for experiments that are
        // about Laravel's queue rather than the broker. The test creates the jobs table.
        $database = getenv('LAB_DATABASE');

        if (is_string($database) && $database !== '') {
            $config->set('database.default', 'lab');
            $config->set('database.connections.lab', [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => false,
                'busy_timeout' => 5000,
                'journal_mode' => 'wal',
                // The dispatcher and the worker write to the same file. A deferred transaction
                // that has to upgrade its lock fails at once with "database is locked"; an
                // immediate one waits for busy_timeout instead.
                'transaction_mode' => 'IMMEDIATE',
            ]);
            $config->set('queue.connections.database', [
                'driver' => 'database',
                'connection' => 'lab',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ]);
        }

        // LAB_AI_URL: the fake OpenAI-compatible server of experiment 6 (bin/fake-ai-provider.php),
        // as two providers of the Laravel AI SDK, a primary and a backup.
        $aiUrl = getenv('LAB_AI_URL');

        if (is_string($aiUrl) && $aiUrl !== '') {
            foreach (['primary', 'backup'] as $provider) {
                $config->set("ai.providers.{$provider}", ['driver' => 'groq', 'key' => 'lab', 'url' => "{$aiUrl}/{$provider}"]);
            }
        }

        // LAB_STORAGE gives the processes of one experiment a storage directory of their own:
        // the maintenance mode flag (artisan down) and the file cache that queue:restart writes
        // to are then shared by the workers and the commands that signal them.
        $storage = getenv('LAB_STORAGE');

        if (is_string($storage) && $storage !== '') {
            $cache = $storage.'/framework/cache/data';

            // Several processes of one experiment may start at once on a fresh directory.
            if (! is_dir($cache) && ! @mkdir($cache, 0777, true) && ! is_dir($cache)) {
                throw new RuntimeException("Could not create {$cache}");
            }

            $app->useStoragePath($storage);
            $config->set('cache.default', 'file');
            $config->set('cache.stores.file.path', $storage.'/framework/cache/data');
        } else {
            $config->set('cache.default', 'array');
        }

        $handler = $app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (Throwable $e): void {
                EventLog::write('exception', ['class' => $e::class, 'code' => $e->getCode(), 'message' => $e->getMessage()]);
            });
        }

        // LAB_KILL_ON_TIMEOUT=0: a timed-out job throws inside the worker instead of killing it.
        if (getenv('LAB_KILL_ON_TIMEOUT') === '0') {
            Worker::$killOnTimeout = false;
        }

        $app['events']->listen(JobDebounced::class, static function (JobDebounced $event): void {
            if ($event->command instanceof DebouncedJob) {
                EventLog::write('debounce.discarded', ['dispatched_at' => $event->command->dispatchedAt, 'forced' => $event->command->delay === 0]);
            }
        });

        $app['events']->listen(AgentFailedOver::class, static fn (AgentFailedOver $event) => EventLog::write('agent.failed_over', ['exception' => $event->exception::class]));

        // A worker that pops a job is not paused; these events show which workers were.
        $app['events']->listen(JobPopping::class, static fn () => EventLog::write('job.popping'));
        $app['events']->listen(WorkerPausing::class, static fn () => EventLog::write('worker.pausing'));
        $app['events']->listen(WorkerQueuePaused::class, static fn (WorkerQueuePaused $event) => EventLog::write('worker.queue_paused', ['queue' => $event->queue]));

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
