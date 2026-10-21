<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Strings\Languages;
use Ernestdefoe\Phrasebook\Strings\Scanner;
use Psr\Http\Message\ServerRequestInterface;

/** How far a locale is from English, per extension. */
class ScanController extends Controller
{
    public function __construct(private readonly Scanner $scanner)
    {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $locale = $this->locale($this->query($request, 'locale'));

        return [
            'locale' => $locale,
            'language' => Languages::name($locale),
            'groups' => $this->scanner->summary($locale),
        ];
    }
}
