<?php

namespace Ernestdefoe\Phrasebook\Tests\unit;

use Ernestdefoe\Phrasebook\Net\Http;
use Ernestdefoe\Phrasebook\Net\Response;

/**
 * Http that answers from a queue instead of the network, recording each
 * request. DNS answers come from a table, so any address can be tested.
 */
class FakeHttp extends Http
{
    /** @var list<array{method: string, target: array<string, mixed>, headers: array<string, string>, body: array<mixed>|null}> */
    public array $sent = [];

    /** @var list<Response> */
    public array $answers = [];

    /**
     * @param array<string, list<string>> $dns
     */
    public function __construct(array $dns = [])
    {
        parent::__construct(fn (string $host) => $dns[$host] ?? ['93.184.215.14']);
    }

    /**
     * @param array<mixed> $json
     * @param array<string, string> $headers
     */
    public function answer(int $status, array $json, array $headers = []): self
    {
        $this->answers[] = new Response($status, $headers, (string) json_encode($json));

        return $this;
    }

    protected function send(string $method, array $target, array $headers, ?string $body, int $timeout): Response
    {
        $this->sent[] = ['method' => $method, 'target' => $target, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];

        return array_shift($this->answers) ?? new Response(500, [], '{}');
    }
}
