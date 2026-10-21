<?php

namespace Ernestdefoe\Phrasebook\Net;

final class Response
{
    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    /**
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }
}
