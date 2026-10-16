# laravel-queue-lab

Reproducible experiments with Laravel queue workers against a real message broker. Each experiment is a PHPUnit test that starts real workers, records what they do and checks the result, so you can rerun it on your own versions.

- [Experiment 1: a job that outlives RabbitMQ's acknowledgement timeout](#experiment-1-a-job-that-outlives-rabbitmqs-acknowledgement-timeout)
- [Experiment 2: a broker that stops answering](#experiment-2-a-broker-that-stops-answering)

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

## Experiment 2: a broker that stops answering

AMQP heartbeats exist so that a client notices a broker that has gone silent. What do `queue:work` and `rabbitmq:consume` do when RabbitMQ stops answering but the TCP connection stays up? [Issue #673](https://github.com/vyuldashev/laravel-queue-rabbitmq/issues/673) in the package explains why `rabbitmq:consume` doesn't notice.

### Setup

- `docker compose pause` freezes the broker processes for 100 s. TCP stays up: Docker's port forwarding and the kernel keep accepting what the client sends, but nothing comes back. A client sees the same when a broker process hangs on a host that still accepts TCP, or behind a TCP proxy. A suspended VM or a network that drops packets is a different case, see the limits below.
- Every worker connects with `heartbeat => 15`. The other connection options keep the defaults that the package takes from php-amqplib: read and write timeouts of 3 s, `connection_timeout` of 3 s, `channel_rpc_timeout` of 0. The package's own default for the heartbeat is 0, which turns it off.
- Three scenarios set `channel_rpc_timeout` to 10 s. php-amqplib doesn't accept an RPC timeout above the read and write timeout, so these scenarios raise those to 10 s as well. One of them also turns the heartbeat off.
- Each worker has its own queue with one quick job, so it is idle when the broker freezes. Workers run with `--sleep=3 --timeout=60`, the Laravel defaults. A worker without its own `--max-time` gets `--max-time=600` as a safety net.

### Result

Times are seconds after the broker froze. After the broker is back, the test watches the workers for 20 s more.

| Scenario | While the broker is frozen | After it is back |
|---|---|---|
| `queue:work` | Waits. Nothing is reported. | `Lost connection: Missed server heartbeat` at once. Stops with `LostConnection` 4 s later, exit code 0 |
| `queue:work --max-time=60` | The same: `--max-time` doesn't fire | The same |
| `queue:work`, `channel_rpc_timeout` 10 s | `AMQPTimeoutException` at 10 s and 24 s, `Lost connection: Missed server heartbeat` at 38 s. Stops with `LostConnection` at 42 s, exit code 0 | — |
| `queue:work`, `channel_rpc_timeout` 10 s, no heartbeat | `AMQPTimeoutException` every 14 s, keeps running | Keeps running |
| `rabbitmq:consume` | Keeps running. Nothing is reported. | Keeps running |
| `rabbitmq:consume --max-time=60` | `WorkerStopping` with `MaxTimeExceeded` at 58 s, but the process doesn't exit | Exits at once, exit code 0 |
| `rabbitmq:consume --max-time=60`, `channel_rpc_timeout` 10 s | `MaxTimeExceeded` at 58 s. 13 s later, `AMQPHeartbeatMissedException` while closing the connection, exit code 1 | — |

Heartbeats alone don't help, and neither does the timeout alone: `queue:work` notices the frozen broker only with both. `rabbitmq:consume` doesn't notice it even then.

### Why

php-amqplib has two checks for missed heartbeats:

- On every read and before every wait ([`AbstractIO::check_heartbeat()`](https://github.com/php-amqplib/php-amqplib/blob/v3.7.5/PhpAmqpLib/Wire/IO/AbstractIO.php)). It compares the time since the last read *or write* with twice the heartbeat timeout. The same method sends the client's own heartbeat every half timeout, so a client that keeps sending heartbeats never fails this check.
- When a wait with a timeout expires ([`AbstractConnection::wait_channel()`](https://github.com/php-amqplib/php-amqplib/blob/v3.7.5/PhpAmqpLib/Connection/AbstractConnection.php)). It compares the time since the last frame from the broker with twice the heartbeat timeout.

What that means for the workers:

- `queue:work` asks for the next job with `basic_get` and waits for the reply up to `channel_rpc_timeout` seconds. 0 means no timeout ([`AMQPIOReader::wait()`](https://github.com/php-amqplib/php-amqplib/blob/v3.7.5/PhpAmqpLib/Wire/AMQPIOReader.php)). The first check runs once, before the wait, and the wait doesn't end while the broker is silent. When the broker is back, the first read finds nothing read or written for 100 s and throws.
- Laravel's `--timeout` doesn't cover this wait. Since Laravel 6.13 ([laravel/framework#31198](https://github.com/laravel/framework/pull/31198)), `Worker::daemon()` sets the alarm after `getNextJob()` returns and clears it at the end of each loop, so no alarm runs while the worker waits for the next job. `--max-time` is checked only after `pop()` returns.
- With `channel_rpc_timeout`, every `basic_get` gives up after 10 s, and the second check runs. Once the last frame is more than 30 s old, php-amqplib throws `AMQPHeartbeatMissedException`. The package rethrows it as `Lost connection: …`, so that Laravel's lost connection check stops the worker. Laravel sleeps 1 s after a failed `pop()` and then 3 s for `--sleep`: hence the stop at 42 s.
- Without a heartbeat neither check runs. A `basic_get` that times out is just an error: Laravel reports it, sleeps and asks again.
- `rabbitmq:consume` polls the channel with a non-blocking `wait()`. The first check runs on every poll, but the client keeps sending its heartbeats and the frozen side accepts them, so it never fails. A non-blocking wait never times out, so the second check never runs. This is what #673 describes.
- `--max-time` ends the consume loop. Then the package closes the connection on `WorkerStopping` ([`RabbitMQConnector`](https://github.com/vyuldashev/laravel-queue-rabbitmq/blob/92ebf0754b3906eae3e84d81c97770171e8ad094/src/Queue/Connectors/RabbitMQConnector.php)). Closing a channel waits for the broker's reply up to `channel_rpc_timeout` seconds: without a timeout, until the broker is back. With 10 s, the channel close gives up, and the connection close waits `connection_timeout` (3 s) and throws `AMQPHeartbeatMissedException`. The command fails with exit code 1.

### What helps

- Turn on heartbeats and set `channel_rpc_timeout`, with `read_timeout` and `write_timeout` at least as long. Leave headroom: any channel RPC that takes longer than the timeout, such as a slow queue declaration, fails with `AMQPTimeoutException`.
- After a lost connection, `queue:work` exits with code 0. As in experiment 1, supervisord's default `autorestart=unexpected` doesn't restart it; `autorestart=true` does.
- No option makes `rabbitmq:consume` notice the frozen broker yet. `--max-time` with `channel_rpc_timeout` limits how long a stuck worker lives; it then exits with code 1, and supervisord restarts it. [PR #674](https://github.com/vyuldashev/laravel-queue-rabbitmq/pull/674), still open, switches the loop to a blocking wait with a timeout, so that the second check runs.

### Limits

- One kind of failure: a broker that stops answering behind an open TCP connection. Not covered: a suspended VM or a network that drops packets, where nothing acknowledges what the client sends. With unacknowledged data in flight, Linux gives up on such a connection after about 15 minutes by default ([`tcp_retries2`](https://docs.kernel.org/networking/ip-sysctl.html)); this isn't tested here. Also not covered: a broker that closes the connection. php-amqplib has an open report about missed heartbeats when the network is cut: [php-amqplib#1227](https://github.com/php-amqplib/php-amqplib/issues/1227).
- The seconds come from local runs with PHP 8.4. The test checks what happens in each scenario, not the exact times.
- The same versions as experiment 1.

## Run it

Requirements: PHP 8.3+ with `pcntl` and `sockets`, Composer and Docker.

```bash
docker compose up -d --wait   # RabbitMQ on 127.0.0.1:5672
composer update
composer test                 # about 5 minutes
docker compose down -v
```

`LAB_AMQP_PORT` changes the port. Experiment 2 freezes the broker with `docker compose pause rabbitmq`, so it needs the broker from `compose.yaml`. The workers write one JSON event per line to a temporary directory; experiment 2 also writes `results.json` there with the seconds from the freeze to each exit. [`tests/AckTimeoutTest.php`](tests/AckTimeoutTest.php) and [`tests/FrozenBrokerTest.php`](tests/FrozenBrokerTest.php) show what is checked. `LAB_HEARTBEAT`, `LAB_IO_TIMEOUT` and `LAB_RPC_TIMEOUT` set the connection options of a process run by hand; the experiments set their own. `bin/lab artisan` treats a failing command like `php artisan` in an application: the exception is reported and printed, and the exit code is 1. To run a worker by hand:

```bash
export LAB_EVENT_LOG=/tmp/events.jsonl
bin/lab push demo A:150 B C
bin/lab artisan rabbitmq:consume rabbitmq --queue=demo --timeout=600
```

## Contributing

A different result on your versions is useful, see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
