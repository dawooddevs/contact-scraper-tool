<?php

declare(strict_types=1);

namespace App\Http;

final class HttpResponse
{
    public ?string $blockReason = null;

    public function __construct(
        public readonly HttpRequest $request,
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $finalUrl,
        public readonly ?string $error,
        public readonly int $timeMs,
        public readonly string $via,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function isHtml(): bool
    {
        $type = strtolower($this->header('content-type'));
        if ($type === '') {
            return stripos(substr($this->body, 0, 1000), '<html') !== false || stripos(substr($this->body, 0, 1000), '<!doctype') !== false;
        }
        return str_contains($type, 'html') || str_contains($type, 'xml');
    }

    public function json(): ?array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }

    public function blocked(): bool
    {
        return $this->blockReason !== null;
    }

    /** Human readable one-line summary for logs. */
    public function describe(): string
    {
        if ($this->error !== null) {
            return $this->error;
        }
        if ($this->blockReason !== null) {
            return $this->blockReason;
        }
        return 'HTTP ' . $this->status;
    }
}
