<?php

// Router for `php -S`: two OpenAI-compatible providers, /primary and /backup, that answer
// /chat/completions from a script. LAB_FAKE_STATE is a directory where the requests are counted.
//
// primary: a call of the SendInvoiceEmail tool, then 429
// backup:  a call of the SendInvoiceEmail tool, then the final answer

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';

if (! preg_match('#^/(primary|backup)/chat/completions$#', $path, $match)) {
    http_response_code(404);

    return true;
}

$provider = $match[1];
$state = getenv('LAB_FAKE_STATE') ?: sys_get_temp_dir();
$counter = $state.'/'.$provider.'.count';
$n = (int) @file_get_contents($counter) + 1;
file_put_contents($counter, (string) $n);

$request = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
$messages = $request['messages'];
$lastMessage = $messages[array_key_last($messages)];
$afterTool = $lastMessage['role'] === 'tool';

$toolCall = [
    'id' => 'chatcmpl-'.$provider.'-'.$n,
    'object' => 'chat.completion',
    'model' => 'openai/gpt-oss-20b',
    'choices' => [[
        'index' => 0,
        'message' => [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_'.$provider.'_'.$n,
                'type' => 'function',
                'function' => ['name' => 'SendInvoiceEmail', 'arguments' => '{}'],
            ]],
        ],
        'finish_reason' => 'tool_calls',
    ]],
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
];

$answer = [
    'id' => 'chatcmpl-'.$provider.'-'.$n,
    'object' => 'chat.completion',
    'model' => 'openai/gpt-oss-20b',
    'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => $afterTool ? $lastMessage['content'] : 'Done.'],
        'finish_reason' => 'stop',
    ]],
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
];

header('Content-Type: application/json');

if ($provider === 'primary' && $afterTool) {
    $pauseFile = $state.'/pause-primary';
    if (is_file($pauseFile)) {
        touch($state.'/primary-waiting');
        $deadline = microtime(true) + 15;
        do {
            usleep(20_000);
            clearstatcache(true, $pauseFile);
        } while (is_file($pauseFile) && microtime(true) < $deadline);
    }
    http_response_code(429);
    echo json_encode(['error' => ['message' => 'Rate limit reached for model openai/gpt-oss-20b.', 'type' => 'tokens', 'code' => 'rate_limit_exceeded']]);

    return true;
}

echo json_encode($afterTool ? $answer : $toolCall);

return true;
