# laravel-queue-lab

Reproducible experiments with Laravel queue workers against a real message broker. Each experiment is a PHPUnit test that starts real workers, records what they do and checks the result, so you can rerun it on your own versions.

## Experiment 1: a job that outlives RabbitMQ's acknowledgement timeout

RabbitMQ closes a channel with `PRECONDITION_FAILED` (406) when a delivery stays unacknowledged for longer than [`consumer_timeout`](https://www.rabbitmq.com/docs/consumers#acknowledgement-timeout), 30 minutes by default. What do `php artisan queue:work` and `php artisan rabbitmq:consume` from [vyuldashev/laravel-queue-rabbitmq](https://github.com/vyuldashev/laravel-queue-rabbitmq) do when a job runs longer than that?

[Issue #664](https://github.com/vyuldashev/laravel-queue-rabbitmq/issues/664) in the package reports that the worker stays alive and stops consuming. On the versions below the worker exits instead, and some jobs run twice.

### Setup

- RabbitMQ 3.13.3 with `consumer_timeout = 60000` ([`rabbitmq/ack-timeout.conf`](rabbitmq/ack-timeout.conf)). The broker checks the timeout about once a minute, so it closes the channel 60–120 s after the delivery.
- One queue per scenario: job A, which sleeps 150 s on its first delivery, then quick jobs B and C.
- Workers run with `--timeout=600`, so Laravel's own job timeout stays out of the way, and `--max-time=420`, so a worker that hangs shows up as a long run.
- After a worker exits, `queue:work --stop-when-empty` drains its queue and records which messages were delivered again.

### Result

| Scenario | What happens after A finishes | Exit code | Delivered again |
|---|---|---|---|
| `queue:work` | The next `pop()` gets the 406 and the one after it `Lost connection: Channel connection is closed.` The worker stops with reason `LostConnection` 4 s later | 0 | A |
| `rabbitmq:consume` | B and C, already in the client buffer, run on the closed channel. Then the 406 arrives from `wait()`, the consume loop ends and the process exits without `WorkerStopping` | 0 | A, B and C |
| `rabbitmq:consume --prefetch-count=1` | The 406 arrives from `wait()` and the process exits | 0 | A |

Nothing hangs. The cost is duplicate work: the ack sent when A finishes goes to a channel the broker has already closed, so A runs again. With the default prefetch, B and C run twice as well.

### Why

- On a `basic.consume` channel the broker returns the unacknowledged messages to the queue as soon as the timeout fires, while the client is still running A ([`rabbit_channel.erl`](https://github.com/rabbitmq/rabbitmq-server/blob/v3.13.3/deps/rabbit/src/rabbit_channel.erl), `handle_exception` and `notify_queues`). With `basic_get`, which `queue:work` uses, the message goes back once the client answers the channel close.
- The client learns about the closed channel only on its next read. php-amqplib then marks the channel closed and clears its consumer callbacks, which ends the `rabbitmq:consume` loop. `queue:work` keeps the closed channel object: the next `basic_get` fails with `Channel connection is closed`, the package reports it as `Lost connection: …`, and Laravel stops the worker.

### What helps

- Keep `consumer_timeout` above your longest job. Since RabbitMQ 3.12 it can be set per queue with the `x-consumer-timeout` argument or the `consumer-timeout` policy.
- Make long jobs idempotent, or split them.
- Use a low `--prefetch-count` for `rabbitmq:consume` workers that run long jobs.
- Both commands exit with code 0 here. supervisord with its default `autorestart=unexpected` doesn't restart such a process; `autorestart=true`, as in the [Laravel docs](https://laravel.com/docs/queues#supervisor-configuration), does.

### Limits

- Classic queues on RabbitMQ 3.13. Starting with RabbitMQ 4.3 the delivery acknowledgement timeout applies to quorum queues only.
- Until [PR #680](https://github.com/vyuldashev/laravel-queue-rabbitmq/pull/680) is released, the package is installed from its branch: with v15.0.2, `rabbitmq:consume` doesn't process jobs on Laravel 13.31+ ([#671](https://github.com/vyuldashev/laravel-queue-rabbitmq/issues/671)).
- Last checked with PHP 8.4, Laravel 13.37, php-amqplib 3.7.5. The same behavior was seen with package versions v14.1.0, v14.4.0 and v14.5.3 on Laravel 11.

## Run it

Requirements: PHP 8.3+ with `pcntl` and `sockets`, Composer and Docker.

```bash
docker compose up -d --wait   # RabbitMQ on 127.0.0.1:5672
composer update
composer test                 # about 3 minutes
docker compose down -v
```

`LAB_AMQP_PORT` changes the port. The workers write one JSON event per line to a temporary directory, and [`tests/AckTimeoutTest.php`](tests/AckTimeoutTest.php) shows what is checked. To run a worker by hand:

```bash
export LAB_EVENT_LOG=/tmp/events.jsonl
bin/lab push demo A:150 B C
bin/lab artisan rabbitmq:consume rabbitmq --queue=demo --timeout=600
```

## Contributing

A different result on your versions is useful, see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
