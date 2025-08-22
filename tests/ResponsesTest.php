<?php

use Orhanerday\OpenAi\ResponsesSSEParser;
use Orhanerday\OpenAi\Url;
use Orhanerday\OpenAi\OpenAi;

it('responses url is correct', function () {
    expect(Url::responsesUrl())->toBe('https://api.openai.com/v1/responses');
})->group('responses');

it('responses() throws when stream true without callback', function () {
    $client = new OpenAi('test-api-key');
    $fn = function () use ($client) {
        $client->responses([
            'model' => 'gpt-4o-mini',
            'input' => 'hi',
            'stream' => true,
        ]);
    };
    expect($fn)->toThrow(Exception::class);
})->group('responses');

it('SSE parser parses events, data and done sentinel', function () {
    $events = [];
    $parser = new ResponsesSSEParser(function (array $e) use (&$events) {
        $events[] = $e;
    });

    $chunk1 = "event: response.output_text.delta\n".
              "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hel\"}\n\n";
    $chunk2 = "event: response.output_text.delta\n".
              "data: {\"type\":\"response.output_text.delta\",\"delta\":\"lo\"}\n\n";
    $chunk3 = "event: response.output_text.done\n".
              "data: {\"type\":\"response.output_text.done\"}\n\n";
    $chunk4 = "data: [DONE]\n\n";

    // Feed in fragmented way to test chunk boundary handling
    $parser->feed(substr($chunk1, 0, 20));
    $parser->feed(substr($chunk1, 20));
    $parser->feed($chunk2);
    $parser->feed($chunk3);
    $parser->feed($chunk4);

    // We expect 4 events: two deltas, one done event, and final done sentinel
    expect($events)->toHaveCount(4);
    expect($events[0]['event'])->toBe('response.output_text.delta');
    expect($events[0]['data']['delta'])->toBe('Hel');
    expect($events[1]['data']['delta'])->toBe('lo');
    expect($events[2]['event'])->toBe('response.output_text.done');
    expect($events[3]['event'])->toBe('done');
    expect($events[3]['data'])->toBe('[DONE]');
})->group('responses');

it('SSE parser reports malformed JSON gracefully', function () {
    $errors = [];
    $events = [];
    $parser = new ResponsesSSEParser(function (array $e) use (&$events) {
        $events[] = $e;
    }, function (string $err) use (&$errors) {
        $errors[] = $err;
    });

    $parser->feed("event: response.error\n");
    $parser->feed("data: {not json}\n\n");

    expect($errors)->not()->toBeEmpty();
    expect($events)->toHaveCount(1);
    expect($events[0]['event'])->toBe('response.error');
    expect($events[0]['data'])->toBe('{not json}');
})->group('responses');
