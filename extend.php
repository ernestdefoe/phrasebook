<?php

use Ernestdefoe\Phrasebook\Api;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Flarum\Extend;

$settings = new Extend\Settings();

foreach (Settings::DEFAULTS as $key => $value) {
    $settings->default(Settings::PREFIX.$key, $value);
}

// Admin-only from end to end: no forum JavaScript, CSS or payload, nothing
// that runs on forum pages, and every route checks for an administrator.
return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        // The review screens, loaded when the page opens.
        ->jsDirectory(__DIR__.'/js/dist/admin')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    $settings,

    (new Extend\Routes('api'))
        ->get('/phrasebook', 'phrasebook.state', Api\StateController::class)
        ->post('/phrasebook/keys/{provider}', 'phrasebook.keys.save', Api\KeyController::class)
        ->delete('/phrasebook/keys/{provider}', 'phrasebook.keys.delete', Api\KeyController::class)
        ->get('/phrasebook/models/{provider}', 'phrasebook.models', Api\ModelsController::class)
        ->post('/phrasebook/test/{provider}', 'phrasebook.test', Api\TestController::class)
        ->get('/phrasebook/scan', 'phrasebook.scan', Api\ScanController::class)
        ->post('/phrasebook/runs', 'phrasebook.runs.start', Api\StartController::class)
        ->get('/phrasebook/runs/{id:\d+}', 'phrasebook.runs.show', Api\RunController::class)
        ->post('/phrasebook/runs/{id:\d+}/{action:step|resume|cancel|accept-all|save}', 'phrasebook.runs.action', Api\RunController::class)
        ->delete('/phrasebook/runs/{id:\d+}', 'phrasebook.runs.delete', Api\RunController::class)
        ->get('/phrasebook/runs/{id:\d+}/items', 'phrasebook.items', Api\ItemsController::class)
        ->patch('/phrasebook/runs/{id:\d+}/items/{item:\d+}', 'phrasebook.items.update', Api\ItemsController::class)
        ->post('/phrasebook/export', 'phrasebook.export', Api\ExportController::class),
];
