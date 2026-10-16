<?php

namespace Lab;

/**
 * Appends one JSON object per line to the file named by LAB_EVENT_LOG. Every worker
 * process gets its own file, so an experiment can tell what happened in which process.
 */
final class EventLog
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function write(string $event, array $data = []): void
    {
        $file = getenv('LAB_EVENT_LOG');

        if ($file === false || $file === '') {
            return;
        }

        $line = json_encode(['time' => microtime(true), 'pid' => getmypid(), 'event' => $event] + $data, JSON_UNESCAPED_SLASHES);

        file_put_contents($file, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function read(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }
}
