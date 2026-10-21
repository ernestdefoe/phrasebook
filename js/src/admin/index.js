import { Admin } from 'flarum/common/extenders';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * The page itself loads on demand: every admin page of every extension
 * shares admin.js, and Phrasebook's screens are only needed here.
 */
let loading = null;

class PhrasebookPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);

    loading ??= import('./components/App').then((module) => module.default);
    loading.then((App) => {
      this.App = App;
      m.redraw();
    });
  }

  sections(vnode) {
    const items = super.sections(vnode);

    items.remove('permissions');
    items.setContent(
      'content',
      <div className="ExtensionPage-settings Phrasebook">
        <div className="container">{this.App ? <this.App /> : <LoadingIndicator />}</div>
      </div>
    );

    return items;
  }
}

export const extend = [new Admin().page(PhrasebookPage)];
