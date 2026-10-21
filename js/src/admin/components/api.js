import app from 'flarum/admin/app';

export const t = (key, params) => app.translator.trans(`ernestdefoe-phrasebook.admin.${key}`, params);

export const SETTING = (name) => `ernestdefoe-phrasebook.${name}`;

/** Settings stored as JSON objects (models, base URLs, prices, styles). */
export function jsonSetting(name) {
  try {
    const value = JSON.parse(app.data.settings[SETTING(name)] || '{}');
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  } catch (e) {
    return {};
  }
}

/**
 * A Phrasebook API call. Errors come back to the caller (no global alert),
 * which shows them where they happened.
 */
export function api(method, path, body) {
  return app.request({
    method,
    url: `${app.forum.attribute('apiUrl')}/phrasebook${path}`,
    body,
    errorHandler: (error) => {
      throw error;
    },
  });
}

/** The first validation message of a failed call, translated where we know it. */
export function errorText(error) {
  const detail = error?.response?.errors?.[0]?.detail;
  const field = error?.response?.errors?.[0]?.source?.pointer?.split('/').pop();

  if (detail && field) {
    const text = known(`errors.${field}_${detail}`);
    if (text) return text;
  }

  if (error?.status === 403) return t('errors.forbidden');

  return detail || t('errors.generic');
}

export function money(value) {
  if (value === null || value === undefined) return '—';
  if (value === 0) return '$0';
  if (value < 0.01) return '<$0.01';
  return `$${value.toFixed(value < 1 ? 3 : 2)}`;
}

export function number(value) {
  return (value || 0).toLocaleString();
}

/** Triggers a file download from a POST response (exports). */
export async function download(path, body, fallbackName) {
  const response = await fetch(`${app.forum.attribute('apiUrl')}/phrasebook${path}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    let json = null;
    try {
      json = await response.json();
    } catch (e) {}
    throw { status: response.status, response: json };
  }

  const blob = await response.blob();
  const name = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') || '')?.[1] || fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** A translation if one exists for this key, else null. */
export function known(key, params) {
  const text = t(key, params);
  return String(text) === `ernestdefoe-phrasebook.admin.${key}` ? null : text;
}

/** A provider failure ({kind, detail}) in words: what happened, then the provider's own reason. */
export function failureText(error) {
  const what = known(`failure.${error.kind}`) || t('failure.other');
  const detail = error.detail ? known(`net.${error.detail}`) || error.detail : null;

  return detail ? [what, ' ', <span className="Phrasebook-detail">({detail})</span>] : what;
}
