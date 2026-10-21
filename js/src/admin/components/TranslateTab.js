import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { api, errorText, jsonSetting, money, number, SETTING, t } from './api';

const LOCALE = /^[a-z]{2,3}(?:[_-][A-Z][a-z]{3})?(?:[_-](?:[A-Z]{2}|\d{3}))?$/;

/**
 * Pick languages, see what each is missing per extension, choose what to
 * translate and how, check the cost, start.
 */
export default class TranslateTab extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    const data = this.attrs.app.data;

    this.locales = data.locales.length ? [data.locales[0].code] : [];
    this.custom = '';
    this.scans = {};
    this.excluded = new Set();
    this.reasons = new Set(['missing', 'stale']);
    this.budget = app.data.settings[SETTING('budget')] || '5';
    this.estimate = null;
    this.error = null;
    this.busy = false;
    this.loadStyle();
    this.scanAll();
  }

  provider() {
    const id = app.data.settings[SETTING('provider')] || 'anthropic';
    return this.attrs.app.data.providers.find((p) => p.id === id);
  }

  model() {
    return jsonSetting('models')[this.provider().id] || '';
  }

  view() {
    const data = this.attrs.app.data;
    const provider = this.provider();
    const model = this.model();

    return (
      <div className="Phrasebook-translate">
        <div className="Form-group">
          <label>{t('translate.languages')}</label>
          <div className="Phrasebook-checks">
            {data.locales.map((l) => this.localeCheck(l.code, `${l.name} (${l.code})`))}
            {this.locales
              .filter((code) => !data.locales.some((l) => l.code === code))
              .map((code) => this.localeCheck(code, `${code} ${t('translate.new_pack')}`))}
          </div>
          <div className="Phrasebook-row">
            <input
              className="FormControl Phrasebook-narrow"
              placeholder="pt_BR"
              value={this.custom}
              aria-label={t('translate.add_language')}
              oninput={(e) => (this.custom = e.target.value.trim())}
              onkeydown={(e) => e.key === 'Enter' && this.addCustom()}
            />
            <Button className="Button" onclick={() => this.addCustom()} disabled={!LOCALE.test(this.custom) || this.custom === 'en'}>
              {t('translate.add_language')}
            </Button>
          </div>
          <p className="helpText">{data.locales.length ? t('translate.languages_help') : t('translate.no_packs')}</p>
        </div>

        {this.locales.map((code) => this.scanView(code))}

        <div className="Form-group">
          <label>{t('translate.which')}</label>
          <div className="Phrasebook-checks">
            {['missing', 'stale', 'identical'].map((reason) => (
              <label className="checkbox">
                <input type="checkbox" checked={this.reasons.has(reason)} onchange={(e) => this.toggle(this.reasons, reason, e.target.checked)} />
                {t(`reasons.${reason}`)}
              </label>
            ))}
          </div>
          <p className="helpText">{t('translate.which_help')}</p>
        </div>

        <div className="Form-group">
          <label for="phrasebook-tone">{t('translate.tone')}</label>
          <Select
            id="phrasebook-tone"
            value={this.tone}
            options={{ neutral: t('translate.tone_neutral'), formal: t('translate.tone_formal'), informal: t('translate.tone_informal') }}
            onchange={(value) => {
              this.tone = value;
              this.estimate = null;
            }}
          />
        </div>

        <div className="Form-group">
          <label for="phrasebook-notes">{t('translate.notes')}</label>
          <textarea
            id="phrasebook-notes"
            className="FormControl"
            rows="2"
            maxlength="1000"
            value={this.notes}
            oninput={(e) => (this.notes = e.target.value)}
          />
          <p className="helpText">{t('translate.notes_help')}</p>
        </div>

        <div className="Form-group">
          <label for="phrasebook-glossary">{t('translate.glossary')}</label>
          <textarea
            id="phrasebook-glossary"
            className="FormControl Phrasebook-mono"
            rows="5"
            maxlength="10000"
            placeholder={'discussion = Diskussion\nFlarum'}
            value={this.glossary}
            oninput={(e) => (this.glossary = e.target.value)}
          />
          <p className="helpText">{t(provider.id === 'google' ? 'translate.glossary_google' : 'translate.glossary_help')}</p>
        </div>

        <div className="Form-group">
          <label for="phrasebook-budget">{t('translate.budget')}</label>
          <div className="Phrasebook-row">
            <span>$</span>
            <input
              id="phrasebook-budget"
              className="FormControl Phrasebook-narrow"
              type="number"
              min="0"
              step="0.5"
              value={this.budget}
              oninput={(e) => (this.budget = e.target.value)}
            />
          </div>
          <p className="helpText">{t('translate.budget_help')}</p>
        </div>

        <p className="Phrasebook-using">
          {model ? t('translate.using', { provider: provider.label, model }) : t('translate.no_model')}{' '}
          <a href="#" onclick={(e) => (e.preventDefault(), this.attrs.app.go('provider'))}>
            {t('translate.change')}
          </a>
        </p>

        {this.estimate ? this.estimateView() : null}

        {this.error ? (
          <p className="Phrasebook-bad" role="alert">
            {this.error}
          </p>
        ) : null}

        <div className="Phrasebook-row">
          <Button
            className="Button"
            icon="fas fa-calculator"
            loading={this.busy === 'estimate'}
            disabled={!this.ready()}
            onclick={() => this.submit(true)}
          >
            {t('translate.estimate')}
          </Button>
          <Button
            className="Button Button--primary"
            icon="fas fa-language"
            loading={this.busy === 'start'}
            disabled={!this.ready() || !this.estimate}
            onclick={() => this.submit(false)}
          >
            {t('translate.start')}
          </Button>
        </div>
        {!this.estimate && this.ready() ? <p className="helpText">{t('translate.estimate_first')}</p> : null}
      </div>
    );
  }

  localeCheck(code, label) {
    return (
      <label className="checkbox">
        <input type="checkbox" checked={this.locales.includes(code)} onchange={(e) => this.toggleLocale(code, e.target.checked)} />
        {label}
      </label>
    );
  }

  scanView(code) {
    const scan = this.scans[code];

    if (!scan) return <LoadingIndicator display="inline" />;
    if (scan.error) return <p className="Phrasebook-bad">{scan.error}</p>;

    const groups = scan.groups.filter((g) => g.missing + g.stale + g.identical > 0);
    const sum = (field) => scan.groups.reduce((n, g) => n + g[field], 0);

    return (
      <div className="Form-group">
        <label>{t('translate.scan_title', { language: scan.language, code })}</label>
        <p className="helpText">
          {t('translate.scan_summary', {
            missing: number(sum('missing')),
            stale: number(sum('stale')),
            identical: number(sum('identical')),
            total: number(sum('total')),
          })}
        </p>
        {groups.length ? (
          <div className="Phrasebook-tableWrap">
            <table className="Phrasebook-table">
              <thead>
                <tr>
                  <th>
                    <input
                      type="checkbox"
                      aria-label={t('translate.all')}
                      checked={groups.every((g) => !this.excluded.has(g.namespace))}
                      onchange={(e) => groups.forEach((g) => this.toggle(this.excluded, g.namespace, !e.target.checked))}
                    />
                  </th>
                  <th>{t('translate.extension')}</th>
                  <th>{t('reasons.missing')}</th>
                  <th>{t('reasons.stale')}</th>
                  <th>{t('reasons.identical')}</th>
                  <th>{t('translate.total')}</th>
                </tr>
              </thead>
              <tbody>
                {groups.map((g) => (
                  <tr>
                    <td>
                      <input
                        type="checkbox"
                        aria-label={g.title}
                        checked={!this.excluded.has(g.namespace)}
                        onchange={(e) => this.toggle(this.excluded, g.namespace, !e.target.checked)}
                      />
                    </td>
                    <td>
                      {g.title} <span className="Phrasebook-muted">{g.namespace}</span>
                    </td>
                    <td>{number(g.missing)}</td>
                    <td>{number(g.stale)}</td>
                    <td>{number(g.identical)}</td>
                    <td>{number(g.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p>{t('translate.complete')}</p>
        )}
      </div>
    );
  }

  estimateView() {
    const e = this.estimate;
    const priced = e.price !== null;

    return (
      <div className="Phrasebook-estimate">
        <h4>{t('translate.estimate_title')}</h4>
        {Object.entries(e.locales).map(([code, est]) => (
          <p>
            <strong>{code}</strong>:{' '}
            {est.characters
              ? t('translate.estimate_mt', { strings: number(est.strings), characters: number(est.characters) })
              : t('translate.estimate_llm', {
                  strings: number(est.strings),
                  batches: number(est.batches),
                  input: number(est.input_tokens),
                  output: number(est.output_tokens),
                })}{' '}
            {priced ? t('translate.estimate_cost', { low: money(est.low), high: money(est.high) }) : null}
          </p>
        ))}
        {!priced ? <p className="Phrasebook-bad">{t('translate.no_price')}</p> : <p className="helpText">{t('translate.estimate_help')}</p>}
      </div>
    );
  }

  ready() {
    return this.locales.length && this.reasons.size && this.model() && !this.busy;
  }

  payload(dry) {
    const namespaces = [];
    Object.values(this.scans).forEach((scan) =>
      (scan.groups || []).forEach((g) => !this.excluded.has(g.namespace) && !namespaces.includes(g.namespace) && namespaces.push(g.namespace))
    );

    return {
      dry,
      locales: this.locales,
      namespaces,
      reasons: [...this.reasons],
      provider: this.provider().id,
      model: this.model(),
      tone: this.tone,
      notes: this.notes,
      glossary: this.glossary,
      budget: this.budget,
    };
  }

  submit(dry) {
    this.busy = dry ? 'estimate' : 'start';
    this.error = null;

    api('POST', '/runs', this.payload(dry))
      .then((result) => {
        if (dry) {
          this.estimate = result;
          return;
        }
        this.attrs.app.reload().then(() => this.attrs.app.go('runs', result.runs[0].id));
      })
      .catch((error) => (this.error = errorText(error)))
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  loadStyle() {
    const first = this.locales[0];
    const style = this.attrs.app.data.locales.find((l) => l.code === first)?.style || jsonSetting('style')[first] || {};

    this.tone = style.tone || 'neutral';
    this.notes = style.notes || '';
    this.glossary = style.glossary || '';
  }

  scanAll() {
    this.locales.forEach((code) => this.scan(code));
  }

  scan(code) {
    if (code in this.scans && this.scans[code] !== undefined && !this.scans[code]?.error) return;

    this.scans[code] = null;
    api('GET', `/scan?locale=${encodeURIComponent(code)}`)
      .then((result) => (this.scans[code] = result))
      .catch((error) => (this.scans[code] = { error: errorText(error) }))
      .finally(() => m.redraw());
  }

  toggleLocale(code, on) {
    this.locales = on ? [...new Set([...this.locales, code])] : this.locales.filter((c) => c !== code);
    this.estimate = null;
    if (on) {
      if (this.locales.length === 1) this.loadStyle();
      this.scan(code);
    }
  }

  addCustom() {
    if (!LOCALE.test(this.custom) || this.custom === 'en') return;
    this.toggleLocale(this.custom, true);
    this.custom = '';
  }

  toggle(set, value, on) {
    on ? set.add(value) : set.delete(value);
    this.estimate = null;
  }
}
