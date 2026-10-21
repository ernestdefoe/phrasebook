<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Export\Exporter;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Downloads a locale's translations: YAML or a language pack zip. POST, not
 * GET: an export also records which English each string was made from.
 */
class ExportController extends Controller
{
    public function __construct(private readonly Exporter $exporter)
    {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $locale = $this->locale($this->input($request, 'locale'));
        $full = $this->input($request, 'scope') === 'full';
        $format = $this->input($request, 'format') === 'pack' ? 'pack' : 'yaml';

        $strings = $this->exporter->strings($locale, $full);

        if ($strings === []) {
            throw new ValidationException(['locale' => 'nothing_to_export']);
        }

        $name = 'flarum-lang-'.strtolower(str_replace('_', '-', $locale));

        if ($format === 'yaml') {
            return (new Response('php://temp', 200, [
                'Content-Type' => 'application/x-yaml; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$name.'.yml"',
                'X-Content-Type-Options' => 'nosniff',
            ]))->withBody($this->stream($this->exporter->yaml($strings)));
        }

        if (! Exporter::zipAvailable()) {
            throw new ValidationException(['format' => 'zip_unavailable']);
        }

        $path = $this->exporter->pack($locale, $strings);
        $body = (string) file_get_contents($path);
        @unlink($path);

        return (new Response('php://temp', 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$name.'.zip"',
            'X-Content-Type-Options' => 'nosniff',
        ]))->withBody($this->stream($body));
    }

    private function stream(string $contents): Stream
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($contents);
        $stream->rewind();

        return $stream;
    }
}
