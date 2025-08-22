<?php

namespace Orhanerday\OpenAi;

/**
 * Minimal SSE parser tailored for OpenAI Responses API streaming.
 * It supports standard SSE lines: "event: <name>" and "data: <payload>".
 * Lines are terminated by a blank line. Chunk boundaries are handled.
 */
class ResponsesSSEParser
{
    /** @var callable */
    private $onEvent;
    /** @var callable|null */
    private $onError;

    private string $buffer = '';
    private ?string $currentEvent = null;
    private string $currentData = '';

    /**
     * @param callable $onEvent function(array $event): void
     * @param callable|null $onError function(string $error): void
     */
    public function __construct(callable $onEvent, ?callable $onError = null)
    {
        $this->onEvent = $onEvent;
        $this->onError = $onError;
    }

    /**
     * Feed a new chunk from the HTTP stream.
     */
    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;
        // Normalize newlines to \n
        $this->buffer = str_replace(["\r\n", "\r"], "\n", $this->buffer);
        $lines = explode("\n", $this->buffer);

        // If the buffer does not end with a newline, the last element is partial; keep it in buffer
        $incomplete = '';
        if ($this->buffer === '' || substr($this->buffer, -1) !== "\n") {
            $incomplete = array_pop($lines);
        } else {
            // Buffer fully consumed
            $incomplete = '';
        }

        foreach ($lines as $line) {
            if ($line === '') {
                // message boundary
                $this->flushMessage();
                continue;
            }

            if (strpos($line, 'event:') === 0) {
                $this->currentEvent = trim(substr($line, strlen('event:')));
                continue;
            }
            if (strpos($line, 'data:') === 0) {
                $dataPart = substr($line, strlen('data:'));
                // Accumulate with newline in case data spans multiple lines
                if ($this->currentData !== '') {
                    $this->currentData .= "\n";
                }
                $this->currentData .= ltrim($dataPart);
                continue;
            }
            // Ignore other SSE fields (id:, retry:, comments)
        }

        $this->buffer = $incomplete;
    }

    private function flushMessage(): void
    {
        // If nothing collected at all, ignore
        if ($this->currentData === '' && $this->currentEvent === null) {
            return;
        }
        // If there is an event name but no data yet, do not emit; wait for data in subsequent chunks
        if ($this->currentData === '' && $this->currentEvent !== null) {
            return;
        }

        $eventName = $this->currentEvent ?? 'message';
        $raw = $this->currentData;

        // Reset state before invoking callbacks to avoid reentrancy issues
        $this->currentEvent = null;
        $this->currentData = '';

        if (trim($raw) === '[DONE]') {
            ($this->onEvent)([
                'event' => 'done',
                'data' => '[DONE]'
            ]);
            return;
        }

        $decoded = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
        }

        if ($decoded === null && $raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
            // Malformed JSON, forward as raw if possible
            if ($this->onError) {
                ($this->onError)('Malformed JSON in SSE data: ' . json_last_error_msg());
            }
            ($this->onEvent)([
                'event' => $eventName,
                'data' => $raw,
            ]);
            return;
        }

        ($this->onEvent)([
            'event' => $eventName,
            'data' => $decoded,
        ]);
    }
}
