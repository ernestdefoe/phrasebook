<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Run\Runs;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A run's strings for review (GET, filtered and paged), and one string's
 * edit/accept/reject/retry (PATCH .../items/{item}).
 */
class ItemsController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly ConnectionInterface $db, private readonly Runs $runs)
    {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $run = $this->run($request);

        if ($request->getMethod() === 'PATCH') {
            $translation = $this->input($request, 'translation');
            $item = $this->runs->update(
                $run,
                (int) Arr::get($request->getQueryParams(), 'item'),
                is_string($translation) ? $translation : null,
                (string) $this->input($request, 'status', '')
            );

            return ['item' => self::item($item)];
        }

        $status = $this->query($request, 'status');
        $namespace = $this->query($request, 'namespace');
        $search = trim($this->query($request, 'q'));
        $page = max(1, (int) $this->query($request, 'page', '1'));

        $query = $this->db->table('phrasebook_items')->where('run_id', $run->id);

        if (in_array($status, ['pending', 'translated', 'failed', 'accepted', 'rejected', 'saved'], true)) {
            $query->where('status', $status);
        }

        if ($namespace !== '') {
            $query->where('namespace', $namespace);
        }

        if ($search !== '') {
            // Bound parameters; % and _ in the search are matched literally.
            $like = '%'.addcslashes(mb_substr($search, 0, 100), '%_\\').'%';
            $query->where(fn ($q) => $q->where('key', 'like', $like)->orWhere('source', 'like', $like)->orWhere('translation', 'like', $like));
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $namespaces = $this->db->table('phrasebook_items')->where('run_id', $run->id)->distinct()->orderBy('namespace')->pluck('namespace')->all();

        return [
            'items' => $rows->map(fn ($row) => self::item($row))->all(),
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'namespaces' => $namespaces,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(object $row): array
    {
        $errors = json_decode((string) $row->errors, true);

        return [
            'id' => (int) $row->id,
            'key' => (string) $row->key,
            'namespace' => (string) $row->namespace,
            'reason' => (string) $row->reason,
            'source' => (string) $row->source,
            'current' => $row->current,
            'translation' => $row->translation,
            'status' => (string) $row->status,
            'attempts' => (int) $row->attempts,
            'errors' => is_array($errors) ? $errors : [],
        ];
    }
}
