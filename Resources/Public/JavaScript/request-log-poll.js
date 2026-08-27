/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Icons from '@typo3/backend/icons.js';

class RequestLogPoll {
  #tbody;
  #pollUrl;
  #modalTitle;
  #viewDetailsLabel;
  #gradePendingLabel;
  #gradeFailedLabel;
  #fallbackLabel;
  #reroutedLabel;
  #successLabel;
  #failedLabel;
  #detailIconHtml = '';
  #interval = 5000;
  #timer = null;
  #lastRowsSignature = '';
  #abortController = new AbortController();

  constructor() {
    this.#tbody = document.querySelector('[data-poll="rows"]');
    if (!this.#tbody) return;

    this.#pollUrl = TYPO3.settings.ajaxUrls.aim_request_log_poll;
    this.#modalTitle = this.#tbody.dataset.modalTitle || '';
    this.#viewDetailsLabel = this.#tbody.dataset.viewDetailsLabel || '';
    this.#gradePendingLabel = this.#tbody.dataset.gradePendingLabel || 'Pending';
    this.#gradeFailedLabel = this.#tbody.dataset.gradeFailedLabel || 'Grade failed';
    this.#fallbackLabel = this.#tbody.dataset.fallbackLabel || 'Fallback';
    this.#reroutedLabel = this.#tbody.dataset.reroutedLabel || 'Rerouted';
    this.#successLabel = this.#tbody.dataset.successLabel || 'Success';
    this.#failedLabel = this.#tbody.dataset.failedLabel || 'Failed';
    this.#lastRowsSignature = Array.from(this.#tbody.rows)
      .map((tr) => `${tr.dataset.uid}:${tr.dataset.gradeStatus}`)
      .join('|');

    this.#loadDetailIcon().finally(() => this.#start());

    document.addEventListener('visibilitychange', this.#handleVisibilityChange, {
      signal: this.#abortController.signal,
    });
  }

  /**
   * Regression fix: this class rebuilds table rows from scratch as plain
   * HTML strings, so it can't reuse Row.html's own
   * `<core:icon identifier="actions-search" alternativeMarkupIdentifier="inline" />`,
   * so it used to fall back to a literal "Details" text label instead,
   * which then never matched the server-rendered row it replaced. Fetches
   * the exact same icon through TYPO3's own client-side Icon API instead,
   * so a poll-refreshed row renders identically to the initial page load.
   */
  async #loadDetailIcon() {
    try {
      this.#detailIconHtml = await Icons.getIcon(
        'actions-search',
        Icons.sizes.default,
        null,
        Icons.states.default,
        Icons.markupIdentifiers.inline,
      );
    } catch {
      // Keep the empty default; #renderDetailLink() falls back to a text
      // label rather than rendering an empty button.
    }
  }

  #handleVisibilityChange = () => {
    if (document.hidden) {
      this.#stop();
    } else {
      this.#poll();
      this.#start();
    }
  };

  #start() {
    this.#stop();
    this.#timer = setTimeout(() => this.#poll(), this.#interval);
  }

  #stop() {
    if (this.#timer) {
      clearTimeout(this.#timer);
      this.#timer = null;
    }
  }

  async #poll() {
    try {
      const response = await new AjaxRequest(this.#pollUrl)
        .withQueryArguments(this.#getFilterParams())
        .get();
      const data = await response.resolve();
      this.#updateStatistics(data.statistics);
      this.#updateRows(data.rows);
    } catch {
      // Silently ignore - next poll will retry
    }
    if (!document.hidden) {
      this.#start();
    }
  }

  #updateStatistics(stats) {
    if (!stats) return;
    const formatters = {
      total_requests: () => Number(stats.total_requests).toLocaleString(),
      total_cost: () => Number(stats.total_cost).toFixed(4),
      total_tokens: () => Number(stats.total_tokens).toLocaleString(),
      success_rate: () => stats.success_rate + '%',
      avg_duration_ms: () => Number(stats.avg_duration_ms).toLocaleString() + ' ms',
    };
    for (const [key, format] of Object.entries(formatters)) {
      const el = document.querySelector(`[data-stat="${key}"]`);
      if (!el) continue;
      const value = format();
      if (el.textContent.trim() !== value.trim()) {
        el.textContent = value;
        this.#flash(el);
      }
    }

    const successRateStat = document.querySelector('[data-stat="success_rate"]')?.closest('.aim-stat');
    if (successRateStat) {
      successRateStat.dataset.level = this.#successRateLevel(Number(stats.success_rate));
    }
  }

  #successRateLevel(rate) {
    if (rate >= 95) return 'good';
    if (rate >= 80) return 'warn';
    return 'critical';
  }

  #rowsSignature(rows) {
    return rows.map((r) => `${r.uid}:${r.grade_status}`).join('|');
  }

  #updateRows(rows) {
    if (!rows || !this.#tbody) return;
    const signature = this.#rowsSignature(rows);
    if (signature === this.#lastRowsSignature) return;

    this.#lastRowsSignature = signature;
    const fragment = document.createDocumentFragment();
    for (const entry of rows) {
      fragment.appendChild(this.#createRow(entry));
    }
    this.#tbody.replaceChildren(fragment);
    this.#flash(this.#tbody);
  }

  #createRow(e) {
    const tr = document.createElement('tr');
    tr.dataset.uid = e.uid;
    tr.dataset.gradeStatus = e.grade_status;
    tr.append(
      this.#tdHtml(this.#renderTimestamp(e)),
      this.#tdHtml(this.#renderExtension(e)),
      this.#tdHtml(this.#renderUser(e)),
      this.#td(e.request_type),
      this.#tdHtml(this.#renderProvider(e)),
      this.#tdHtml(this.#renderModel(e)),
      this.#tdHtml(this.#renderTokens(e)),
      this.#td(e.cost),
      this.#td(e.duration_ms + ' ms'),
      this.#tdHtml(this.#renderGrade(e)),
      this.#tdHtml(this.#renderStatus(e)),
      // col-control matches Row.html's own last <td> - without it, this
      // column's width shifts once a poll redraws the row.
      this.#tdHtml(this.#renderDetailLink(e), 'col-control'),
    );
    return tr;
  }

  #renderTimestamp(e) {
    if (!e.detailUrl) return this.#esc(e.crdate);
    const link = `<a href="${this.#esc(e.detailUrl)}">${this.#esc(e.crdate)}</a>`;
    return e.detailModalUrl
      ? `<aim-request-log-trigger modal-url="${this.#esc(e.detailModalUrl)}" modal-title="${this.#esc(this.#modalTitle)}">${link}</aim-request-log-trigger>`
      : link;
  }

  #renderDetailLink(e) {
    if (!e.detailUrl) return '';
    const icon = this.#detailIconHtml || 'Details';
    const link = `<a href="${this.#esc(e.detailUrl)}" class="btn btn-default btn-sm" title="${this.#esc(this.#viewDetailsLabel)}">${icon}</a>`;
    return e.detailModalUrl
      ? `<aim-request-log-trigger modal-url="${this.#esc(e.detailModalUrl)}" modal-title="${this.#esc(this.#modalTitle)}">${link}</aim-request-log-trigger>`
      : link;
  }

  #td(text) {
    const td = document.createElement('td');
    td.textContent = text ?? '';
    return td;
  }

  #tdHtml(html, className = '') {
    const td = document.createElement('td');
    if (className) td.className = className;
    td.innerHTML = html;
    return td;
  }

  #renderExtension(e) {
    if (!e.extension_key) return '<span class="text-body-secondary">-</span>';
    const icon = e.extension_icon
      ? `<img src="${this.#esc(e.extension_icon)}" width="16" height="16" alt=""> `
      : '';
    return icon + this.#esc(e.extension_key);
  }

  #renderUser(e) {
    if (e.username) return `<span class="text-body-secondary">${this.#esc(e.username)}</span>`;
    if (e.user_id) return `<span class="text-body-tertiary">#${this.#esc(String(e.user_id))}</span>`;
    return '';
  }

  #renderGrade(e) {
    if (e.grade_status === 'done') {
      // Map, not a plain object literal: grade_label is server data, and a
      // value like "toString" would otherwise resolve through
      // Object.prototype to a function instead of falling through to the
      // 'critical' default, stringifying into a garbage data-tone value.
      const toneByLabel = new Map([['excellent', 'good'], ['good', 'info'], ['fair', 'warn']]);
      const tone = toneByLabel.get(e.grade_label) ?? 'critical';
      const score = Number(e.grade_score).toFixed(2);
      return `<span class="aim-chip" data-tone="${tone}" title="${this.#esc(e.grade_reason)}">${this.#esc(e.grade_label)} (${score})</span>`;
    }
    if (e.grade_status === 'pending') {
      return `<span class="aim-chip" data-tone="neutral">${this.#esc(this.#gradePendingLabel)}</span>`;
    }
    if (e.grade_status === 'failed') {
      return `<span class="aim-chip" data-tone="warn" title="${this.#esc(e.grade_error)}">${this.#esc(this.#gradeFailedLabel)}</span>`;
    }
    return '<span class="text-body-tertiary">-</span>';
  }

  #renderProvider(e) {
    return e.configuration_edit_url
      ? `<a href="${this.#esc(e.configuration_edit_url)}">${this.#esc(e.provider_identifier)}</a>`
      : this.#esc(e.provider_identifier);
  }

  #renderModel(e) {
    let html = `<span class="badge badge-secondary">${this.#esc(e.model_used)}</span>`;
    if (e.model_used && e.model_requested && e.model_used !== e.model_requested) {
      html += `<br><small class="text-body-secondary">requested: ${this.#esc(e.model_requested)}</small>`;
    }
    return html;
  }

  #renderTokens(e) {
    // Number(...) coerces each value, same defense-in-depth as
    // #esc() elsewhere: safe today only because the PHP side already casts
    // these to int, but not dependent on that holding forever.
    let html = `<strong>${Number(e.total_tokens).toLocaleString()}</strong>`
      + `<br><small class="text-body-secondary">${Number(e.prompt_tokens)} / ${Number(e.completion_tokens)}</small>`;
    if (e.cached_tokens > 0) {
      html += `<br><small class="text-success">cached: ${Number(e.cached_tokens)}</small>`;
    }
    if (e.reasoning_tokens > 0) {
      html += `<br><small class="text-warning">reasoning: ${Number(e.reasoning_tokens)}</small>`;
    }
    return html;
  }

  #renderStatus(e) {
    let html = '';
    if (e.rerouted) {
      const tone = e.reroute_type === 'fallback' ? 'info' : 'warn';
      const label = e.reroute_type === 'fallback' ? this.#fallbackLabel : this.#reroutedLabel;
      html += `<span class="aim-chip" data-tone="${tone}" title="${this.#esc(e.reroute_reason)}">${this.#esc(label)}</span> `;
    }
    html += e.success
      ? `<span class="aim-chip" data-tone="good">${this.#esc(this.#successLabel)}</span>`
      : `<span class="aim-chip" data-tone="critical" title="${this.#esc(e.error_message)}">${this.#esc(this.#failedLabel)}</span>`;
    return html;
  }

  #flash(el) {
    el.style.transition = 'none';
    el.style.backgroundColor = 'rgba(var(--typo3-state-primary-bg-rgb, 13, 110, 253), 0.08)';
    requestAnimationFrame(() => {
      el.style.transition = 'background-color 1.5s ease';
      el.style.backgroundColor = '';
    });
  }

  #esc(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  #getFilterParams() {
    const form = document.querySelector('form[name="demand"]');
    if (!form) return {};

    const params = {};
    const names = [
      'demand[provider_identifier]',
      'demand[extension_key]',
      'demand[request_type]',
      'demand[success]',
    ];
    for (const name of names) {
      const el = form.querySelector(`[name="${name}"]`);
      if (el?.value) {
        params[name] = el.value;
      }
    }
    return params;
  }
}

export default new RequestLogPoll();
