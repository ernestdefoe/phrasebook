import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import { api, errorText, t } from './api';
import ProviderTab from './ProviderTab';
import TranslateTab from './TranslateTab';
import RunsTab from './RunsTab';

/** Phrasebook's admin page: Provider, Translate, Runs. */
export default class App extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.tab = 'translate';
    this.data = null;
    this.error = null;
    this.openRun = null;
    this.reload();
  }

  reload() {
    return api('GET', '')
      .then((data) => {
        this.data = data;
        this.error = null;
        // A first visit with no key saved starts on the Provider tab.
        if (!this.visited && !data.providers.some((p) => p.hasKey || p.key === 'optional')) this.tab = 'provider';
        this.visited = true;
      })
      .catch((error) => (this.error = errorText(error)))
      .finally(() => m.redraw());
  }

  view() {
    if (this.error) return <p className="Phrasebook-error">{this.error}</p>;
    if (!this.data) return <LoadingIndicator />;

    const tabs = ['translate', 'runs', 'provider'];
    const app = { data: this.data, reload: () => this.reload(), go: (tab, run) => this.go(tab, run) };

    return (
      <div className="Phrasebook-app">
        <p className="helpText">{t('intro')}</p>
        <nav className="Phrasebook-tabs" role="tablist">
          {tabs.map((tab) => (
            <Button
              className={'Button Button--text Phrasebook-tab' + (this.tab === tab ? ' active' : '')}
              role="tab"
              aria-selected={this.tab === tab}
              onclick={() => this.go(tab)}
            >
              {t(`tabs.${tab}`)}
              {tab === 'runs' && this.data.runs.some((r) => ['queued', 'running', 'waiting'].includes(r.status)) ? (
                <span className="Phrasebook-dot" aria-hidden="true" />
              ) : null}
            </Button>
          ))}
        </nav>
        <div className="Phrasebook-panel">{this.panel(app)}</div>
      </div>
    );
  }

  panel(app) {
    if (this.tab === 'provider') return <ProviderTab app={app} />;
    if (this.tab === 'translate') return <TranslateTab app={app} />;

    // Keyed, so opening another run starts its review afresh.
    return [<RunsTab app={app} open={this.openRun} key={this.openRun || 'list'} />];
  }

  go(tab, run = null) {
    this.tab = tab;
    this.openRun = run;
    if (tab === 'runs' && !run) this.reload();
  }
}
