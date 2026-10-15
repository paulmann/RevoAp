<?php
/**
 * SseDeltaParser: OpenAI-compatible `data: {...}` frames, turned into text.
 *
 * The dialect was captured from the live provider rather than read from a
 * specification: frames arrive as `data: <json>` lines separated by blank
 * lines, each carrying `choices[0].delta.content`, and the stream ends with
 * `data: [DONE]`. A usage block rides in one of the last frames when it was
 * asked for.
 *
 * Two details decide whether this survives production traffic:
 *
 *   - a frame can be split across network chunks, in the middle of a line or in
 *     the middle of the `data:` prefix, so bytes are buffered until a newline
 *     arrives and only whole lines are interpreted;
 *   - a delta with an empty or missing content is normal (the first frame often
 *     carries only the role), so it contributes nothing rather than an error.
 *
 * Pure and stateful: feed it chunks, read the text as it grows.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Connectors;

final class SseDeltaParser
{
    private string $buffer = '';
    private string $text = '';
    private ?array $usage = null;
    private ?string $finishReason = null;
    private ?string $generationId = null;
    private bool $done = false;

    /**
     * Feed raw bytes; get back the text that arrived with them.
     *
     * @return array{text:string,usage:?array,done:bool}
     */
    public function push(string $chunk): array
    {
        if ($this->done || $chunk === '') {
            return ['text' => '', 'usage' => $this->usage, 'done' => $this->done];
        }
        $this->buffer .= $chunk;
        $fresh = '';
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = substr($this->buffer, $pos + 1);
            $fresh .= $this->consumeLine($line);
        }
        $this->text .= $fresh;
        return ['text' => $fresh, 'usage' => $this->usage, 'done' => $this->done];
    }

    /** Everything received so far. */
    public function text(): string
    {
        return $this->text;
    }

    /** The usage block the provider reported, if any. */
    public function usage(): ?array
    {
        return $this->usage;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    public function generationId(): ?string
    {
        return $this->generationId;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** @return string the text this line contributed (usually empty) */
    private function consumeLine(string $line): string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, ':')) {
            return ''; // keep-alive comment
        }
        if (!str_starts_with($line, 'data:')) {
            return ''; // `event:` and friends carry no content here
        }
        $data = trim(substr($line, 5));
        if ($data === '[DONE]') {
            $this->done = true;
            return '';
        }
        $frame = json_decode($data, true);
        if (!is_array($frame)) {
            return ''; // a frame we do not understand must not kill the stream
        }
        if ($this->generationId === null && isset($frame['id']) && is_string($frame['id']) && $frame['id'] !== '') {
            $this->generationId = $frame['id'];
        }
        if (is_array($frame['usage'] ?? null)) {
            $this->usage = $frame['usage'];
        }
        $choice = is_array($frame['choices'][0] ?? null) ? $frame['choices'][0] : null;
        if ($choice === null) {
            return '';
        }
        if (isset($choice['finish_reason']) && is_string($choice['finish_reason']) && $choice['finish_reason'] !== '') {
            $this->finishReason = $choice['finish_reason'];
        }
        // Some providers close with a full message instead of a delta.
        if (is_array($choice['message'] ?? null) && is_string($choice['message']['content'] ?? null)) {
            $text = (string)$choice['message']['content'];
            // Only use it when nothing streamed: otherwise it would duplicate.
            return $this->text === '' && $this->buffer === '' ? $text : '';
        }
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : null;
        if ($delta === null || !is_string($delta['content'] ?? null)) {
            return '';
        }
        return (string)$delta['content'];
    }
}