<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Run\Runs;
use Flarum\Foundation\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Accept and push all": the finished runs the admin confirmed accept what
 * came back valid and go to Linguist together.
 */
class PushAllController extends Controller
{
    public function __construct(private readonly Runs $runs)
    {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $ids = $this->input($request, 'ids');
        $ids = is_array($ids) ? array_slice(array_values(array_unique(array_filter($ids, fn ($id) => is_int($id) && $id > 0))), 0, 50) : [];

        if ($ids === []) {
            throw new ValidationException(['ids' => 'invalid']);
        }

        return ['results' => $this->runs->pushAll($ids)];
    }
}
