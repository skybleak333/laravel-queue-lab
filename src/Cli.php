<?php

namespace Lab;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * bin/lab push <queue> <job>...   push jobs, job = name[:seconds], e.g. A:150 B C
 * bin/lab size <queue>            print the number of ready messages
 * bin/lab artisan <command> ...   run an Artisan command, e.g. artisan queue:work rabbitmq --queue=q
 * bin/lab debounce <seconds> <ms> dispatch DebouncedJob to the database queue every <ms> ms
 * bin/lab max-exceptions <queue>  dispatch MaxExceptionsJob "timeout" and "throw" to the database queue
 */
final class Cli
{
    /**
     * @param  list<string>  $argv
     */
    public static function run(array $argv): int
    {
        $app = Lab::app();

        if (($argv[1] ?? '') === 'debounce') {
            $end = microtime(true) + (float) ($argv[2] ?? 60);
            $interval = (int) ($argv[3] ?? 500);

            while (microtime(true) < $end) {
                // The pending dispatch takes the debounce lock and sets the delay when it is
                // destroyed, so it is unset before the delay is read.
                $pending = DebouncedJob::dispatch(microtime(true))->onConnection('database')->onQueue('debounce');
                $job = $pending->getJob();
                unset($pending);

                EventLog::write('debounce.dispatch', ['dispatched_at' => $job->dispatchedAt, 'forced' => $job->delay === 0]);

                usleep($interval * 1000);
            }

            return 0;
        }

        if (($argv[1] ?? '') === 'agent-failover') {
            AgentJob::dispatch()->onConnection('database')->onQueue($argv[2] ?? 'default');

            return 0;
        }

        if (($argv[1] ?? '') === 'max-exceptions') {
            foreach (['timeout', 'throw'] as $mode) {
                MaxExceptionsJob::dispatch($mode, $mode)->onConnection('database')->onQueue($argv[2] ?? 'default');
            }

            return 0;
        }

        switch ($argv[1] ?? '') {
            case 'push':
                $queue = $app['queue']->connection('rabbitmq');

                foreach (array_slice($argv, 3) as $spec) {
                    [$name, $seconds] = array_pad(explode(':', $spec, 2), 2, '0');

                    $queue->push(new SlowJob($name, (int) $seconds), '', $argv[2]);
                }

                return 0;

            case 'size':
                echo $app['queue']->connection('rabbitmq')->size($argv[2]), PHP_EOL;

                return 0;

            case 'artisan':
                $kernel = $app->make(Kernel::class);
                $input = new ArgvInput(array_merge(['artisan'], array_slice($argv, 2)));
                $output = new ConsoleOutput;

                try {
                    $status = $kernel->handle($input, $output);
                } catch (Throwable $e) {
                    // Testbench's console kernel rethrows what a command throws. The kernel of a
                    // Laravel application reports it, prints it and returns 1, and so does the lab.
                    $handler = $app->make(ExceptionHandler::class);
                    $handler->report($e);
                    $handler->renderForConsole($output, $e);
                    $status = 1;
                }

                EventLog::write('process.exit', ['status' => $status]);
                $kernel->terminate($input, $status);

                return $status;

            default:
                fwrite(STDERR, "usage: bin/lab push <queue> <job>... | size <queue> | artisan <command> ... | debounce <seconds> <ms> | max-exceptions <queue> | agent-failover <queue>\n");

                return 2;
        }
    }
}
