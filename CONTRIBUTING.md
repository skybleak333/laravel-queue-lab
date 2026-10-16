# Contributing

The experiments are only useful if they match what happens in real setups.

- **A different result?** Open an issue with your PHP, Laravel, `vladimir-yuldashev/laravel-queue-rabbitmq`, php-amqplib and RabbitMQ versions, the command you ran and the event log.
- **A new experiment?** Open an issue first with the question it answers. An experiment is a PHPUnit test in `tests/` that runs real workers against the broker from `compose.yaml` and checks the outcome.

Before opening a pull request, run the experiments as described in the README.
