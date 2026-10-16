<?php

namespace Lab;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * bin/lab push <queue> <job>...   push jobs, job = name[:seconds], e.g. A:150 B C
 * bin/lab size <queue>            print the number of ready messages
 * bin/lab artisan <command> ...   run an Artisan command, e.g. artisan queue:work rabbitmq --queue=q
 */
final class Cli
{
    /**
     * @param  list<string>  $argv
     */
    public static function run(array $argv): int
    {
        $app = Lab::app();
        $queue = $app['queue']->connection('rabbitmq');

        switch ($argv[1] ?? '') {
            case 'push':
                foreach (array_slice($argv, 3) as $spec) {
                    [$name, $seconds] = array_pad(explode(':', $spec, 2), 2, '0');

                    $queue->push(new SlowJob($name, (int) $seconds), '', $argv[2]);
                }

                return 0;

            case 'size':
                echo $queue->size($argv[2]), PHP_EOL;

                return 0;

            case 'artisan':
                $kernel = $app->make(Kernel::class);
                $input = new ArgvInput(array_merge(['artisan'], array_slice($argv, 2)));

                $status = $kernel->handle($input, new ConsoleOutput);

                EventLog::write('process.exit', ['status' => $status]);
                $kernel->terminate($input, $status);

                return $status;

            default:
                fwrite(STDERR, "usage: bin/lab push <queue> <job>... | size <queue> | artisan <command> ...\n");

                return 2;
        }
    }
}
