<?php

declare(strict_types=1);

namespace App\Api;

/**
 * A streamed file download (e.g. CSV export) returned by a controller.
 */
final class FileResponse
{
    /** @param callable(resource):void $writer */
    public function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly mixed $writer,
    ) {
    }

    public function send(): void
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->filename) ?: 'export';
        header('Content-Type: ' . $this->contentType);
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($this->filename));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        ($this->writer)($out);
        fclose($out);
    }
}
