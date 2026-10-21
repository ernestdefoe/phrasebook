import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import { api, download, errorText, failureText, known, money, number, t } from './api';

const ACTIVE = ['queued', 'running', 'waiting'];
const STATUSES = ['translated', 'failed', 'pending', 'accepted', 'rejected', 'saved'];

/**
 * The list of runs, and one run's review: progress, then every string with
 * its English, what the language has today, and the proposed translation,
 * each editable, to accept or reject before saving or exporting.
 */
export default class RunsTab extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.runId = this.attrs.open;
    this.run = null;
    this.counts = null;
    this.items = null;
    this.filter = { status: 'translated', namespace: '', q: '', page: 1 };
    this.edits = {};
    this.rowErrors = {};
    this.message = null;
    this.export = { scope: 'phrasebook', format: 'yaml' };

    if (this.runId) this.load();
  }

  onremove() {
    this.stopped = true;
    clearTimeout(this.timer);
  }

  view() {
    if (!this.runId) return this.listView();
    if (!this.run) return <LoadingIndicator />;

    return this.reviewView();
  }

  listView() {
    const runs = this.attrs.app.data.runs;

    if (!runs.length) return <p className="helpText">{t('runs.none')}</p>;

    return (
      <div className="Phrasebook-tableWrap">
        <table className="Phrasebook-table">
          <thead>
            <tr>
              <th>{t('runs.language')}</th>
              <th>{t('runs.model')}</th>
              <th>{t('runs.status')}</th>
              <th>{t('runs.spent')}</th>
              <th>{t('runs.started')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {runs.map((run) => (
              <tr>
                <td>
                  {run.language} <span className="Phrasebook-muted">{run.locale}</span>
                </td>
                <td>{run.model}</td>
                <td>
                  <span className={`Phrasebook-status is-${run.status}`}>{t(`status.${run.status}`)}</span>
                </td>
                <td>
                  {money(run.spent)} / {money(run.budget)}
                </td>
                <td>{run.createdAt ? humanTime(run.createdAt) : null}</td>
                <td>
                  <Button className="Button" onclick={() => this.attrs.app.go('runs', run.id)}>
                    {t('runs.open')}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  reviewView() {
    const run = this.run;
    const counts = this.counts;
    const total = Object.values(counts).reduce((a, b) => a + b, 0);
    const done = total - counts.pending;
    const active = ACTIVE.includes(run.status);
    const data = this.attrs.app.data;

    return (
      <div className="Phrasebook-review">
        <p>
          <a href="#" onclick={(e) => (e.preventDefault(), this.attrs.app.go('runs'))}>
            ← {t('runs.back')}
          </a>
        </p>
        <h3>
          {run.language} <span className="Phrasebook-muted">{run.locale}</span> · {run.model}
        </h3>

        <div className="Phrasebook-progress" role="progressbar" aria-valuemin="0" aria-valuemax={total} aria-valuenow={done}>
          <div style={{ width: `${total ? (100 * done) / total : 0}%` }} />
        </div>
        <p>
          <span className={`Phrasebook-status is-${run.status}`}>{t(`status.${run.status}`)}</span>{' '}
          {t('runs.progress', { done: number(done), total: number(total), spent: money(run.spent), budget: money(run.budget) })}{' '}
          {run.characters
            ? t('runs.characters', { count: number(run.characters) })
            : t('runs.tokens', { input: number(run.inputTokens), output: number(run.outputTokens) })}
        </p>
        {this.statusNote(run)}

        <div className="Phrasebook-row">
          {active ? (
            <Button className="Button" icon="fas fa-pause" onclick={() => this.action('cancel')}>
              {t('runs.cancel')}
            </Button>
          ) : null}
          {['paused', 'cancelled'].includes(run.status) || (run.status === 'review' && counts.pending) ? (
            <Button className="Button" icon="fas fa-play" onclick={() => this.action('resume')}>
              {t('runs.resume')}
            </Button>
          ) : null}
          {active && data.queued && this.stuck() ? (
            <Button className="Button" icon="fas fa-play" onclick={() => this.drive(true)}>
              {t('runs.continue_here')}
            </Button>
          ) : null}
          <Button className="Button" icon="fas fa-check-double" disabled={!counts.translated} onclick={() => this.action('accept-all')}>
            {t('runs.accept_all', { count: number(counts.translated) })}
          </Button>
          <Button
            className="Button Button--primary"
            icon="fas fa-save"
            disabled={!counts.accepted || !data.linguist}
            title={data.linguist ? null : t('runs.linguist_missing')}
            onclick={() => this.action('save')}
          >
            {t('runs.save', { count: number(counts.accepted) })}
          </Button>
          <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.remove()}>
            {t('runs.delete')}
          </Button>
        </div>
        {!data.linguist ? <p className="helpText">{t('runs.linguist_missing')}</p> : null}

        <fieldset className="Phrasebook-export">
          <legend>{t('export.title')}</legend>
          <div className="Phrasebook-row">
            <Select
              value={this.export.scope}
              options={{ phrasebook: t('export.scope_phrasebook'), full: t('export.scope_full') }}
              onchange={(v) => (this.export.scope = v)}
            />
            <Select
              value={this.export.format}
              options={data.zip ? { yaml: t('export.format_yaml'), pack: t('export.format_pack') } : { yaml: t('export.format_yaml') }}
              onchange={(v) => (this.export.format = v)}
            />
            <Button className="Button" icon="fas fa-download" onclick={() => this.download()}>
              {t('export.download')}
            </Button>
          </div>
          <p className="helpText">{t('export.help')}</p>
        </fieldset>

        {this.message ? (
          <p className="Phrasebook-message" role="status">
            {this.message}
          </p>
        ) : null}

        {this.filters(counts)}
        {this.itemsView()}
      </div>
    );
  }

  statusNote(run) {
    if (run.status === 'waiting') return <p className="helpText">{t('runs.waiting', { error: known(`failure.${run.error}`) || run.error })}</p>;
    if (run.status === 'paused') {
      if (run.error === 'budget') return <p className="Phrasebook-bad">{t('runs.paused_budget')}</p>;
      if (run.error === 'no_price') return <p className="Phrasebook-bad">{t('runs.paused_price')}</p>;
      return <p className="Phrasebook-bad">{failureText({ kind: run.error, detail: run.message })}</p>;
    }
    if (ACTIVE.includes(run.status) && !this.attrs.app.data.queued) return <p className="helpText">{t('runs.keep_open')}</p>;
    if (ACTIVE.includes(run.status)) return <p className="helpText">{t('runs.queued_note')}</p>;

    return null;
  }

  filters(counts) {
    return (
      <div className="Phrasebook-filters">
        <div className="Phrasebook-row" role="tablist">
          {STATUSES.map((status) => (
            <Button
              className={'Button Button--text' + (this.filter.status === status ? ' active' : '')}
              role="tab"
              aria-selected={this.filter.status === status}
              onclick={() => this.setFilter({ status })}
            >
              {t(`items.${status}`)} ({number(counts[status])})
            </Button>
          ))}
        </div>
        <div className="Phrasebook-row">
          <Select
            value={this.filter.namespace}
            options={Object.fromEntries([['', t('items.all_extensions')], ...(this.namespaces || []).map((n) => [n, n])])}
            onchange={(namespace) => this.setFilter({ namespace })}
          />
          <input
            className="FormControl"
            type="search"
            placeholder={t('items.search')}
            value={this.filter.q}
            onchange={(e) => this.setFilter({ q: e.target.value })}
          />
        </div>
      </div>
    );
  }

  itemsView() {
    if (this.items === null) return <LoadingIndicator />;
    if (!this.items.length) return <p className="helpText">{t('items.none')}</p>;

    const pages = Math.ceil(this.total / this.perPage);

    return (
      <div>
        <ol className="Phrasebook-items">{this.items.map((item) => this.itemView(item))}</ol>
        {pages > 1 ? (
          <div className="Phrasebook-row">
            <Button className="Button" disabled={this.filter.page <= 1} onclick={() => this.setFilter({ page: this.filter.page - 1 }, false)}>
              {t('items.previous')}
            </Button>
            <span>{t('items.page', { page: this.filter.page, pages })}</span>
            <Button className="Button" disabled={this.filter.page >= pages} onclick={() => this.setFilter({ page: this.filter.page + 1 }, false)}>
              {t('items.next')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  itemView(item) {
    const value = this.edits[item.id] ?? item.translation ?? '';
    const errors = this.rowErrors[item.id] || item.errors;
    const editable = !['saved'].includes(item.status);

    // Every value here is shown as text: Mithril escapes it; nothing from a
    // provider is ever rendered as HTML.
    return (
      <li className={`Phrasebook-item is-${item.status}`} key={item.id}>
        <div className="Phrasebook-itemHead">
          <code className="Phrasebook-key">{item.key}</code>
          <span className={`Phrasebook-status is-${item.status}`}>{t(`items.${item.status}`)}</span>
          <span className="Phrasebook-muted">{t(`reasons.${item.reason}`)}</span>
        </div>
        <dl>
          <dt>{t('items.english')}</dt>
          <dd>
            <code>{item.source}</code>
          </dd>
          {item.current !== null && item.current !== undefined
            ? [
                <dt>{t('items.current')}</dt>,
                <dd>
                  <code>{item.current}</code>
                </dd>,
              ]
            : null}
          <dt>
            <label for={`phrasebook-item-${item.id}`}>{t('items.translation')}</label>
          </dt>
          <dd>
            <textarea
              id={`phrasebook-item-${item.id}`}
              className="FormControl Phrasebook-mono"
              rows={Math.min(8, Math.max(1, Math.ceil(value.length / 80)))}
              value={value}
              disabled={!editable}
              dir="auto"
              oninput={(e) => (this.edits[item.id] = e.target.value)}
            />
          </dd>
        </dl>
        {errors && errors.length ? (
          <ul className="Phrasebook-errors">
            {errors.map((error) => (
              <li>
                {known(`validation.${error.code}`, { detail: error.code.startsWith('argument_') ? `{${error.detail}}` : error.detail }) ||
                  known(`failure.${error.code.replace(/^provider_/, '')}`) ||
                  error.code}
                {error.detail && error.code.startsWith('provider_') ? <span className="Phrasebook-detail"> ({error.detail})</span> : null}
              </li>
            ))}
          </ul>
        ) : null}
        {editable ? (
          <div className="Phrasebook-row">
            <Button className="Button Button--primary" icon="fas fa-check" onclick={() => this.update(item, 'accepted')}>
              {this.edits[item.id] !== undefined ? t('items.save_accept') : t('items.accept')}
            </Button>
            {this.edits[item.id] !== undefined ? (
              <Button className="Button" onclick={() => this.update(item, item.status === 'failed' ? 'translated' : item.status)}>
                {t('items.save_edit')}
              </Button>
            ) : null}
            <Button className="Button" icon="fas fa-times" onclick={() => this.update(item, 'rejected')}>
              {t('items.reject')}
            </Button>
            <Button className="Button" icon="fas fa-redo" onclick={() => this.update(item, 'pending')}>
              {t('items.retry')}
            </Button>
          </div>
        ) : null}
      </li>
    );
  }

  load() {
    return api('GET', `/runs/${this.runId}`)
      .then((result) => this.apply(result))
      .then(() => this.loadItems())
      .then(() => this.drive());
  }

  apply(result) {
    this.run = result.run;
    this.counts = result.counts;
    if (result.saved !== undefined) this.message = t('runs.saved_message', { saved: number(result.saved), skipped: number(result.skipped) });
    if (result.accepted !== undefined) this.message = t('runs.accepted_message', { count: number(result.accepted) });
    this.lastChange ??= Date.now();
    m.redraw();
  }

  loadItems() {
    const f = this.filter;
    const query = `status=${f.status}&namespace=${encodeURIComponent(f.namespace)}&q=${encodeURIComponent(f.q)}&page=${f.page}`;

    return api('GET', `/runs/${this.runId}/items?${query}`)
      .then((result) => {
        this.items = result.items;
        this.total = result.total;
        this.perPage = result.perPage;
        this.namespaces = result.namespaces;
      })
      .catch((error) => (this.message = errorText(error)))
      .finally(() => m.redraw());
  }

  setFilter(changes, resetPage = true) {
    Object.assign(this.filter, changes, resetPage ? { page: 1 } : {});
    this.items = null;
    this.loadItems();
  }

  /**
   * Keeps the page current while the run works. Without a queue worker the
   * page does the work itself, one batch per request; with one, it polls.
   */
  drive(force = false) {
    clearTimeout(this.timer);

    if (this.stopped || !ACTIVE.includes(this.run.status)) return;

    const here = force || this.driving || !this.attrs.app.data.queued;
    this.driving = here;

    let wait = 0;
    if (this.run.status === 'waiting' && this.run.retryAt) wait = Math.max(0, new Date(this.run.retryAt) - Date.now());

    this.timer = setTimeout(
      () => {
        const before = JSON.stringify(this.counts);
        const call = here ? api('POST', `/runs/${this.runId}/step`) : api('GET', `/runs/${this.runId}`);

        call
          .then((result) => {
            this.apply(result);
            this.idle = JSON.stringify(this.counts) === before;
            if (!this.idle) {
              this.lastChange = Date.now();
              this.loadItems();
            }
          })
          .catch((error) => (this.message = errorText(error)))
          .finally(() => this.drive());
      },
      // Back to back while batches land; slower when nothing moved (another
      // process holds the run, or it is between retries).
      here ? Math.max(wait, this.idle ? 2000 : 250) : Math.max(wait, 3000)
    );
  }

  /** A queued run nobody has touched for a minute: the worker may not be running. */
  stuck() {
    return !this.driving && Date.now() - (this.lastChange || Date.now()) > 60000;
  }

  action(name) {
    if (name === 'save' && !confirm(t('runs.save_confirm', { count: number(this.counts.accepted), locale: this.run.locale }))) return;

    this.message = null;
    api('POST', `/runs/${this.runId}/${name}`)
      .then((result) => {
        this.apply(result);
        this.lastChange = Date.now();
        this.loadItems();
        this.drive();
        this.attrs.app.reload();
      })
      .catch((error) => (this.message = errorText(error)))
      .finally(() => m.redraw());
  }

  update(item, status) {
    const body = { status };
    if (this.edits[item.id] !== undefined) body.translation = this.edits[item.id];

    api('PATCH', `/runs/${this.runId}/items/${item.id}`, body)
      .then((result) => {
        delete this.edits[item.id];
        delete this.rowErrors[item.id];
        Object.assign(item, result.item);
        return api('GET', `/runs/${this.runId}`).then((r) => this.apply(r));
      })
      .then(() => this.drive())
      .catch((error) => {
        const detail = error?.response?.errors?.[0]?.detail;
        let parsed = null;
        try {
          parsed = JSON.parse(detail);
        } catch (e) {}
        this.rowErrors[item.id] = Array.isArray(parsed) ? parsed : [{ code: 'invalid', detail: '' }];
      })
      .finally(() => m.redraw());
  }

  remove() {
    if (!confirm(t('runs.delete_confirm'))) return;

    api('DELETE', `/runs/${this.runId}`).then(() => this.attrs.app.go('runs'));
  }

  download() {
    this.message = null;
    download(
      '/export',
      { locale: this.run.locale, ...this.export },
      `flarum-lang-${this.run.locale}.${this.export.format === 'pack' ? 'zip' : 'yml'}`
    ).catch((error) => {
      this.message = errorText(error);
      m.redraw();
    });
  }
}
