import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import saveSettings from 'flarum/admin/utils/saveSettings';
import { api, errorText, failureText, jsonSetting, money, SETTING, t } from './api';

const GROUPS = {
  llm: ['anthropic', 'openai', 'gemini', 'mistral', 'deepseek', 'groq', 'openrouter'],
  local: ['ollama', 'lmstudio', 'custom'],
  mt: ['deepl', 'google'],
};

/**
 * Which provider and model to use, the admin's own key (write-only: it is
 * never shown again, only its last four characters), base URLs for local
 * and compatible servers, prices, and a test call.
 */
export default class ProviderTab extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.selected = app.data.settings[SETTING('provider')] || 'anthropic';
    this.keyInput = '';
    this.models = {};
    this.modelErrors = {};
    this.message = null;
    this.test = null;
    this.busy = false;
    this.loadModels(this.selected);
  }

  provider() {
    return this.attrs.app.data.providers.find((p) => p.id === this.selected);
  }

  view() {
    const provider = this.provider();
    const models = jsonSetting('models');
    const baseUrls = jsonSetting('base_urls');
    const model = models[provider.id] || '';
    const list = this.models[provider.id];
    const price = jsonSetting('prices')[`${provider.id}|${model}`] || {};
    const options = {};

    Object.entries(GROUPS).forEach(([group, ids]) => {
      ids.forEach((id) => {
        const p = this.attrs.app.data.providers.find((x) => x.id === id);
        options[id] = `${p.label}${p.hasKey ? ' ✓' : ''}`;
      });
    });

    return (
      <div className="Phrasebook-provider">
        <div className="Form-group">
          <label for="phrasebook-provider">{t('provider.choose')}</label>
          <Select id="phrasebook-provider" value={this.selected} options={options} onchange={(id) => this.select(id)} />
          <p className="helpText">{t(`provider.kind_${provider.kind}`)}</p>
        </div>

        <div className="Form-group">
          <label for="phrasebook-key">
            {t('provider.key')}
            {provider.key === 'optional' ? ` ${t('provider.optional')}` : ''}
          </label>
          {provider.hasKey ? (
            <div className="Phrasebook-row">
              <span className="Phrasebook-saved">{t('provider.key_saved', { hint: provider.keyHint })}</span>
              <Button className="Button" onclick={() => this.removeKey()} disabled={this.busy}>
                {t('provider.key_remove')}
              </Button>
            </div>
          ) : null}
          <div className="Phrasebook-row">
            <input
              id="phrasebook-key"
              className="FormControl"
              type="password"
              autocomplete="off"
              spellcheck="false"
              placeholder={provider.hasKey ? t('provider.key_replace') : t('provider.key_placeholder')}
              value={this.keyInput}
              oninput={(e) => (this.keyInput = e.target.value)}
            />
            <Button className="Button Button--primary" onclick={() => this.saveKey()} disabled={this.busy || !this.keyInput.trim()}>
              {t('provider.key_save')}
            </Button>
          </div>
          <p className="helpText">
            {t('provider.key_help')}{' '}
            {provider.keyUrl ? (
              <a href={provider.keyUrl} target="_blank" rel="noopener noreferrer">
                {t(provider.local ? 'provider.local_link' : 'provider.key_link')}
              </a>
            ) : null}
          </p>
        </div>

        {provider.editable ? (
          <div className="Form-group">
            <label for="phrasebook-base">{t('provider.base_url')}</label>
            <input
              id="phrasebook-base"
              className="FormControl"
              type="url"
              placeholder="https://…/v1"
              value={baseUrls[provider.id] ?? ''}
              onchange={(e) => this.saveJson('base_urls', provider.id, e.target.value.trim()).then(() => this.loadModels(provider.id, true))}
            />
            <p className="helpText">{t('provider.base_url_help')}</p>
            <Switch
              state={app.data.settings[SETTING('allow_local')] === '1'}
              onchange={(value) => this.save({ [SETTING('allow_local')]: value ? '1' : '0' }).then(() => this.loadModels(provider.id, true))}
            >
              {t('provider.allow_local')}
            </Switch>
            <p className="helpText">{t('provider.allow_local_help')}</p>
          </div>
        ) : null}

        <div className="Form-group">
          <label for="phrasebook-model">{t('provider.model')}</label>
          <div className="Phrasebook-row">
            <input
              id="phrasebook-model"
              className="FormControl"
              list="phrasebook-models"
              spellcheck="false"
              value={model}
              placeholder={t('provider.model_placeholder')}
              onchange={(e) => this.saveJson('models', provider.id, e.target.value.trim())}
            />
            <Button className="Button" icon="fas fa-sync" onclick={() => this.loadModels(provider.id, true)} disabled={list === null}>
              {t('provider.model_refresh')}
            </Button>
          </div>
          <datalist id="phrasebook-models">
            {(list || []).map((m) => (
              <option value={m.id}>{m.label !== m.id ? m.label : null}</option>
            ))}
          </datalist>
          <p className="helpText">
            {list === null
              ? t('provider.models_loading')
              : this.modelErrors[provider.id]
                ? t('provider.models_failed', { reason: this.modelErrors[provider.id] })
                : list
                  ? t('provider.models_count', { count: list.length })
                  : null}
          </p>
        </div>

        {model ? this.priceView(provider, model, price) : null}

        {provider.kind === 'llm' ? (
          <div className="Form-group">
            <label for="phrasebook-effort">{t('provider.effort')}</label>
            <Select
              id="phrasebook-effort"
              value={app.data.settings[SETTING('effort')] || 'low'}
              options={{ low: t('provider.effort_low'), medium: t('provider.effort_medium'), high: t('provider.effort_high') }}
              onchange={(value) => this.save({ [SETTING('effort')]: value })}
            />
            <p className="helpText">{t('provider.effort_help')}</p>
          </div>
        ) : null}

        <div className="Form-group">
          <label for="phrasebook-batch">{t('provider.batch_size')}</label>
          <input
            id="phrasebook-batch"
            className="FormControl Phrasebook-narrow"
            type="number"
            min="1"
            max="100"
            value={app.data.settings[SETTING('batch_size')] || '25'}
            onchange={(e) => this.save({ [SETTING('batch_size')]: String(Math.max(1, Math.min(100, parseInt(e.target.value, 10) || 25))) })}
          />
          <p className="helpText">{t('provider.batch_size_help')}</p>
        </div>

        <div className="Form-group">
          <Button className="Button" icon="fas fa-vial" loading={this.test === 'loading'} disabled={!model} onclick={() => this.runTest(model)}>
            {t('provider.test')}
          </Button>
          {this.testView()}
        </div>

        {this.message ? (
          <p className="Phrasebook-message" role="status">
            {this.message}
          </p>
        ) : null}
      </div>
    );
  }

  priceView(provider, model, own) {
    const resolved = provider.model === model ? provider.price : null;
    const mt = provider.kind === 'mt';
    const field = (name, label) => (
      <label className="Phrasebook-price">
        <span>{label}</span>
        <input
          className="FormControl"
          type="number"
          min="0"
          step="any"
          value={own[name] ?? ''}
          placeholder={resolved && resolved[name] !== undefined ? String(resolved[name]) : ''}
          onchange={(e) => this.savePrice(provider.id, model, name, e.target.value)}
        />
      </label>
    );

    return (
      <div className="Form-group">
        <label>{t('provider.price')}</label>
        <div className="Phrasebook-row">
          {mt ? field('char', t('provider.price_char')) : [field('in', t('provider.price_in')), field('out', t('provider.price_out'))]}
        </div>
        <p className="helpText">
          {resolved ? t(`provider.price_source_${resolved.source}`) : t('provider.price_unknown')}{' '}
          {provider.pricingUrl ? (
            <a href={provider.pricingUrl} target="_blank" rel="noopener noreferrer">
              {t('provider.price_link')}
            </a>
          ) : null}
        </p>
      </div>
    );
  }

  testView() {
    if (!this.test || this.test === 'loading') return null;

    if (!this.test.ok) {
      return <p className="Phrasebook-bad">{this.failure(this.test.error)}</p>;
    }

    return (
      <div className="Phrasebook-test">
        {this.test.samples.map((s) => (
          <div className={'Phrasebook-sample ' + (s.errors.length ? 'is-bad' : 'is-good')}>
            <code>{s.source}</code>
            <span aria-hidden="true">→</span>
            <code>{s.translation ?? '—'}</code>
            <span>{s.errors.length ? t('provider.test_rejected') : t('provider.test_ok')}</span>
          </div>
        ))}
        <p className="helpText">{t('provider.test_cost', { model: this.test.model, cost: money(this.test.cost) })}</p>
      </div>
    );
  }

  failure(error) {
    return failureText(error);
  }

  select(id) {
    this.selected = id;
    this.keyInput = '';
    this.test = null;
    this.save({ [SETTING('provider')]: id });
    if (this.models[id] === undefined) this.loadModels(id);
  }

  loadModels(id, refresh = false) {
    const provider = this.attrs.app.data.providers.find((p) => p.id === id);

    if (provider.key === 'required' && !provider.hasKey) {
      this.models[id] = undefined;
      return Promise.resolve();
    }

    this.models[id] = null;
    this.modelErrors[id] = null;

    return api('GET', `/models/${id}${refresh ? '?refresh=1' : ''}`)
      .then((result) => {
        this.models[id] = result.models;
        if (result.error) this.modelErrors[id] = this.failure(result.error);
      })
      .catch((error) => {
        this.models[id] = [];
        this.modelErrors[id] = errorText(error);
      })
      .finally(() => m.redraw());
  }

  saveKey() {
    const id = this.selected;
    this.busy = true;

    return api('POST', `/keys/${id}`, { key: this.keyInput.trim() })
      .then(() => {
        this.keyInput = '';
        this.message = t('provider.key_saved_message');
        return this.attrs.app.reload();
      })
      .then(() => this.loadModels(id, true))
      .catch((error) => (this.message = errorText(error)))
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  removeKey() {
    if (!confirm(t('provider.key_remove_confirm'))) return;

    this.busy = true;
    api('DELETE', `/keys/${this.selected}`)
      .then(() => this.attrs.app.reload())
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  save(values) {
    return saveSettings(values)
      .then(() => this.attrs.app.reload())
      .catch((error) => (this.message = errorText(error)));
  }

  saveJson(name, key, value) {
    const all = jsonSetting(name);
    if (value === '') delete all[key];
    else all[key] = value;

    return this.save({ [SETTING(name)]: JSON.stringify(all) });
  }

  savePrice(provider, model, field, value) {
    const all = jsonSetting('prices');
    const id = `${provider}|${model}`;
    const row = { ...(all[id] || {}) };
    const number = parseFloat(value);

    if (value === '' || isNaN(number) || number < 0) delete row[field];
    else row[field] = number;

    if (Object.keys(row).length) all[id] = row;
    else delete all[id];

    return this.save({ [SETTING('prices')]: JSON.stringify(all) });
  }

  runTest(model) {
    this.test = 'loading';
    const locale = this.attrs.app.data.locales[0]?.code || 'de';

    api('POST', `/test/${this.selected}`, { model, locale })
      .then((result) => (this.test = result))
      .catch((error) => (this.test = { ok: false, error: { kind: 'other', detail: errorText(error) } }))
      .finally(() => m.redraw());
  }
}
