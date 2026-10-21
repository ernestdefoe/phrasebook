<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Run\Run;
use Ernestdefoe\Phrasebook\Strings\Languages;
use Flarum\Foundation\ValidationException;
use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every Phrasebook endpoint is for administrators only: the admin check runs
 * before anything else, in handle(), so no subclass can forget it.
 */
abstract class Controller implements RequestHandlerInterface
{
    abstract protected function respond(ServerRequestInterface $request): mixed;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $result = $this->respond($request);

        return $result instanceof ResponseInterface ? $result : new JsonResponse($result);
    }

    protected function input(ServerRequestInterface $request, string $key, mixed $default = null): mixed
    {
        return Arr::get((array) $request->getParsedBody(), $key, $default);
    }

    protected function query(ServerRequestInterface $request, string $key, string $default = ''): string
    {
        $value = Arr::get($request->getQueryParams(), $key, $default);

        return is_string($value) ? $value : $default;
    }

    protected function locale(mixed $value): string
    {
        if (! is_string($value) || ! Languages::valid($value)) {
            throw new ValidationException(['locale' => 'invalid']);
        }

        // Spelled the way the installed pack spells it, or Linguist rows are
        // written under a code Flarum never reads.
        return Languages::canonical($value, array_keys(resolve(LocaleManager::class)->getLocales()));
    }

    protected function run(ServerRequestInterface $request): Run
    {
        $run = Run::query()->find((int) Arr::get($request->getQueryParams(), 'id'));

        if (! $run instanceof Run) {
            throw new RouteNotFoundException();
        }

        return $run;
    }

    /**
     * @return list<string>
     */
    protected function strings(mixed $value, int $max = 500): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter($value, fn ($v) => is_string($v) && $v !== '' && strlen($v) <= 100))), 0, $max);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Run $run): array
    {
        return [
            'id' => $run->id,
            'locale' => $run->locale,
            'language' => Languages::name($run->locale),
            'provider' => $run->provider,
            'model' => $run->model,
            'status' => $run->status,
            'budget' => (float) $run->budget,
            'spent' => (float) $run->spent,
            'inputTokens' => (int) $run->input_tokens,
            'outputTokens' => (int) $run->output_tokens,
            'characters' => (int) $run->characters,
            'calls' => (int) $run->calls,
            'error' => $run->error,
            'message' => $run->message,
            'retryAt' => $run->status === 'waiting' ? $run->next_attempt_at?->toIso8601String() : null,
            'tone' => $run->tone,
            'createdAt' => $run->created_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
        ];
    }
}
