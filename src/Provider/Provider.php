<?php

namespace Ernestdefoe\Phrasebook\Provider;

interface Provider
{
    /**
     * The models this key can use, from the provider's own list.
     *
     * @return list<array{id: string, label: string, price?: array{in: float, out: float}}>
     *
     * @throws ProviderError
     */
    public function models(): array;

    /**
     * @return array{translations: array<string, string>, usage: Usage}
     *
     * @throws ProviderError the call failed
     * @throws BatchFailed the call was answered (and billed) but the answer can't be used
     */
    public function translate(Job $job): array;

    /** Called when a run ends, to tidy up anything made for it. */
    public function finish(Job $job): void;
}
