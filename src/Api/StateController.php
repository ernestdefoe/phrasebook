<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Export\Exporter;
use Ernestdefoe\Phrasebook\Provider\Catalog;
use Ernestdefoe\Phrasebook\Provider\Pricing;
use Ernestdefoe\Phrasebook\Run\Run;
use Ernestdefoe\Phrasebook\Run\Runs;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Ernestdefoe\Phrasebook\Settings\Vault;
use Ernestdefoe\Phrasebook\Strings\Languages;
use Flarum\Extension\ExtensionManager;
use Flarum\Locale\LocaleManager;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Everything the admin page needs to draw itself. Keys appear only as
 * "saved, ending in …".
 */
class StateController extends Controller
{
    public function __construct(
        private readonly Vault $vault,
        private readonly Settings $settings,
        private readonly Pricing $pricing,
        private readonly Runs $runs,
        private readonly LocaleManager $locales,
        private readonly ExtensionManager $extensions
    ) {
    }

    /**
     * Linguist before 2.0.0 stores its strings outside Flarum's ICU domain,
     * so a saved translation with a placeholder or plural renders wrongly
     * wherever the server writes it (emails): "A {Ana} le gustó".
     */
    private static function linguistOutdated(): bool
    {
        if (! class_exists(\Composer\InstalledVersions::class) || ! \Composer\InstalledVersions::isInstalled('fof/linguist')) {
            return false;
        }

        $version = (string) \Composer\InstalledVersions::getVersion('fof/linguist');

        return $version !== '' && ! str_starts_with($version, 'dev-') && version_compare($version, '2.0.0.0', '<');
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $hints = $this->vault->hints();
        $providers = [];

        foreach (Catalog::PROVIDERS as $id => $def) {
            $model = $this->settings->model($id);
            $providers[] = [
                'id' => $id,
                'label' => $def['label'],
                'kind' => $def['kind'],
                'key' => $def['key'],
                'editable' => $def['editable'],
                'local' => ! empty($def['local']),
                'keyUrl' => $def['keyUrl'],
                'pricingUrl' => $def['pricingUrl'],
                'hasKey' => isset($hints[$id]),
                'keyHint' => isset($hints[$id]) ? '…'.$hints[$id] : null,
                'model' => $model,
                'price' => $model === '' ? null : $this->pricing->for($id, $model),
            ];
        }

        $locales = [];
        foreach ($this->locales->getLocales() as $code => $name) {
            if ($code !== 'en') {
                $locales[] = ['code' => $code, 'name' => $name, 'english' => Languages::name($code), 'style' => $this->settings->style($code)];
            }
        }

        return [
            'providers' => $providers,
            'locales' => $locales,
            'queued' => $this->runs->queued(),
            'linguist' => $this->extensions->isEnabled('fof-linguist'),
            'linguistOutdated' => $this->extensions->isEnabled('fof-linguist') && self::linguistOutdated(),
            'zip' => Exporter::zipAvailable(),
            'runs' => Run::query()->orderByDesc('id')->limit(20)->get()->map(fn (Run $run) => self::present($run))->all(),
        ];
    }
}
