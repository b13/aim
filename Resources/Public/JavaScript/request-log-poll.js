/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class RequestLogPoll {
  #tbody;
  #pollUrl;
  #interval = 5000;
  #timer = null;
  #lastRowCount = 0;
  #abortController = new AbortController();

  constructor() {
    this.#tbody = document.querySelector('[data-poll="rows"]');
    if (!this.#tbody) return;

    this.#pollUrl = TYPO3.settings.ajaxUrls.aim_request_log_poll;
    this.#lastRowCount = this.#tbody.rows.length;

    this.#start();

    document.addEventListener('visibilitychange', this.#handleVisibilityChange, {
      signal: this.#abortController.signal,
    });
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
    this.#start();
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
  }

  #updateRows(rows) {
    if (!rows || !this.#tbody) return;
    if (rows.length === this.#lastRowCount) {
      const firstCell = this.#tbody.rows[0]?.cells[0]?.textContent?.trim();
      if (rows[0] && firstCell === rows[0].crdate) return;
    }

    this.#lastRowCount = rows.length;
    const fragment = document.createDocumentFragment();
    for (const entry of rows) {
      fragment.appendChild(this.#createRow(entry));
    }
    this.#tbody.replaceChildren(fragment);
    this.#flash(this.#tbody);
  }

  #createRow(e) {
    const tr = document.createElement('tr');
    tr.append(
      this.#td(e.crdate),
      this.#tdHtml(this.#renderExtBadge(e.extension_key)),
      this.#td(e.request_type),
      this.#td(e.provider_identifier),
      this.#tdHtml(this.#renderModel(e)),
      this.#tdHtml(this.#renderTokens(e)),
      this.#td(e.cost),
      this.#td(e.duration_ms + ' ms'),
      this.#tdHtml(this.#renderStatus(e)),
    );
    return tr;
  }

  #td(text) {
    const td = document.createElement('td');
    td.textContent = text ?? '';
    return td;
  }

  #tdHtml(html) {
    const td = document.createElement('td');
    td.innerHTML = html;
    return td;
  }

  #renderExtBadge(key) {
    return key
      ? `<span class="badge badge-info">${this.#esc(key)}</span>`
      : '<span class="text-body-secondary">-</span>';
  }

  #renderModel(e) {
    let html = `<span class="badge badge-secondary">${this.#esc(e.model_used)}</span>`;
    if (e.model_used && e.model_requested && e.model_used !== e.model_requested) {
      html += `<br><small class="text-body-secondary">requested: ${this.#esc(e.model_requested)}</small>`;
    }
    return html;
  }

  #renderTokens(e) {
    let html = `<strong>${Number(e.total_tokens).toLocaleString()}</strong>`
      + `<br><small class="text-body-secondary">${e.prompt_tokens} / ${e.completion_tokens}</small>`;
    if (e.cached_tokens > 0) {
      html += `<br><small class="text-success">cached: ${e.cached_tokens}</small>`;
    }
    if (e.reasoning_tokens > 0) {
      html += `<br><small class="text-warning">reasoning: ${e.reasoning_tokens}</small>`;
    }
    return html;
  }

  #renderStatus(e) {
    let html = '';
    if (e.rerouted) {
      const type = e.reroute_type === 'fallback' ? 'info' : 'warning';
      const label = e.reroute_type === 'fallback' ? 'Fallback' : 'Rerouted';
      html += `<span class="badge badge-${type}" title="${this.#esc(e.reroute_reason)}">${label}</span> `;
    }
    html += e.success
      ? '<span class="badge badge-success">Success</span>'
      : `<span class="badge badge-danger" title="${this.#esc(e.error_message)}">Failed</span>`;
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
    return d.innerHTML;
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
