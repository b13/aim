/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import Modal from '@typo3/backend/modal.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { injectConsoleStyles } from '@b13/aim/console-styles.js';

/**
 * Mirrors the robustness of core's own copyToClipboard()
 * (@typo3/backend/copy-to-clipboard.js: awaits the actual write, falls back
 * to document.execCommand('copy') when the Clipboard API isn't available),
 * without importing that module directly. Doing so also registers its
 * Lit-based <typo3-copy-to-clipboard> custom element as an import side
 * effect, and Lit's cached constructed stylesheet for that Shadow DOM
 * component is tied to the document/realm it was first created in. Since
 * this module's content runs inside the backend's own content iframe (a
 * different document than wherever else in the backend that class might
 * already be cached), adopting the cached stylesheet there throws
 * "NotAllowedError: ... Sharing constructed stylesheets in multiple
 * documents is not allowed" as soon as the modal opens.
 *
 * Deliberately resolves navigator/document from the given element's own
 * ownerDocument/defaultView rather than the plain module-scope globals:
 * Modal.advanced(), when called from inside this content iframe, reuses
 * parent.window.TYPO3.Modal (see modal.js), so the <typo3-backend-modal>
 * element it builds, and the button the user actually clicks, end up living
 * in the PARENT document, not the iframe this module was loaded into. The
 * Clipboard API's permission/activation state is tied to the document the
 * click actually happened in, so calling the iframe's own navigator.clipboard
 * from here would be asking the wrong realm and fail silently.
 */
function copyTextToClipboard(sourceElement, text) {
  const ownerDocument = sourceElement.ownerDocument;
  const view = ownerDocument.defaultView || window;

  if (view.navigator.clipboard) {
    return view.navigator.clipboard.writeText(text);
  }
  return new Promise((resolve, reject) => {
    const textarea = ownerDocument.createElement('textarea');
    textarea.value = text;
    ownerDocument.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
      ownerDocument.execCommand('copy') ? resolve() : reject(new Error('document.execCommand("copy") returned false'));
    } catch (e) {
      reject(e);
    } finally {
      ownerDocument.body.removeChild(textarea);
    }
  });
}

// The whole modal body is built here rather than server-rendered, since
// nothing about it is page- or request-specific; only the AJAX response,
// filled in after the fact, is dynamic. Handed to Modal as a plain DOM node
// (Modal.types.default), so later mutating it (rendering the result, wiring
// button handlers) is safe; see console-styles.js's docblock on why that's
// NOT true for Modal.types.ajax's root.
const TEMPLATE = `
  <div class="aim-console aim-calibrate-voice">
    <div class="alert alert-warning" data-role="no-provider" hidden>
      <p data-role="no-provider-message"></p>
      <a class="btn btn-default btn-sm" data-role="no-provider-link"></a>
    </div>
    <p class="aim-caller-note" data-role="intro"></p>
    <div class="aim-calibrate-voice__pages">
      <button type="button" class="btn btn-default btn-sm" data-role="select-pages"></button>
      <span class="aim-spinner" data-role="pages-spinner" hidden></span>
      <span class="text-body-secondary" data-role="pages-status"></span>
    </div>
    <textarea class="form-control" rows="8" data-role="input"></textarea>
    <div class="aim-calibrate-voice__actions">
      <button type="button" class="btn aim-btn-primary" data-role="analyze"></button>
      <span class="text-body-secondary" data-role="loading" hidden></span>
    </div>
    <p class="text-danger" data-role="error" hidden></p>
    <div class="aim-calibrate-voice__results" data-role="result" hidden>
      <div class="aim-master">
        <span class="aim-master__corner aim-master__corner--tl"></span>
        <span class="aim-master__corner aim-master__corner--tr"></span>
        <span class="aim-master__corner aim-master__corner--bl"></span>
        <span class="aim-master__corner aim-master__corner--br"></span>
        <div class="aim-master__head">
          <span class="aim-master__name" data-role="tone-label"></span>
          <button type="button" class="btn btn-default btn-sm" data-role="copy-tone"></button>
        </div>
        <pre class="aim-master__readout" data-role="tone"></pre>
      </div>
      <div class="aim-master">
        <span class="aim-master__corner aim-master__corner--tl"></span>
        <span class="aim-master__corner aim-master__corner--tr"></span>
        <span class="aim-master__corner aim-master__corner--bl"></span>
        <span class="aim-master__corner aim-master__corner--br"></span>
        <div class="aim-master__head">
          <span class="aim-master__name" data-role="examples-label"></span>
          <button type="button" class="btn btn-default btn-sm" data-role="copy-examples"></button>
        </div>
        <pre class="aim-master__readout" data-role="examples"></pre>
      </div>
    </div>
  </div>
`;

class AimCalibrateVoiceTrigger extends HTMLElement {
  #abortController;
  #button;
  // Tracks every page inserted across repeated "Select page" clicks within
  // one open calibration modal, so the status line can list all of them
  // (not just the last one) and a page picked twice is a silent no-op the
  // second time. Reset whenever the calibration modal itself is (re)opened.
  #insertedUids;
  #insertedLabels;

  connectedCallback() {
    this.#abortController = new AbortController();
    this.#button = this.querySelector('button');
    if (!this.#button) return;

    this.#button.addEventListener('click', this.#handleClick, { signal: this.#abortController.signal });
  }

  disconnectedCallback() {
    this.#abortController?.abort();
  }

  get url() {
    return this.getAttribute('url');
  }

  get modalTitle() {
    return this.getAttribute('modal-title') || '';
  }

  #handleClick = (e) => {
    e.preventDefault();
    this.#openModal();
  };

  #openModal() {
    const panel = document.createElement('div');
    panel.innerHTML = TEMPLATE;
    injectConsoleStyles(panel);

    const noProvider = panel.querySelector('[data-role="no-provider"]');
    const intro = panel.querySelector('[data-role="intro"]');
    const pagesRow = panel.querySelector('.aim-calibrate-voice__pages');
    const selectPagesButton = panel.querySelector('[data-role="select-pages"]');
    const pagesSpinner = panel.querySelector('[data-role="pages-spinner"]');
    const pagesStatus = panel.querySelector('[data-role="pages-status"]');
    const textarea = panel.querySelector('[data-role="input"]');
    const actions = panel.querySelector('.aim-calibrate-voice__actions');
    const analyzeButton = panel.querySelector('[data-role="analyze"]');
    const loading = panel.querySelector('[data-role="loading"]');
    const errorEl = panel.querySelector('[data-role="error"]');
    const resultEl = panel.querySelector('[data-role="result"]');
    const toneEl = panel.querySelector('[data-role="tone"]');
    const examplesEl = panel.querySelector('[data-role="examples"]');

    if (this.dataset.hasProvider === '0') {
      panel.querySelector('[data-role="no-provider-message"]').textContent = this.dataset.noProviderMessage || '';
      const link = panel.querySelector('[data-role="no-provider-link"]');
      link.textContent = this.dataset.noProviderLinkLabel || '';
      link.href = this.dataset.providersUrl || '#';
      noProvider.hidden = false;
      intro.hidden = true;
      pagesRow.hidden = true;
      textarea.hidden = true;
      actions.hidden = true;

      Modal.advanced({
        type: Modal.types.default,
        size: Modal.sizes.medium,
        title: this.modalTitle,
        content: panel,
      });
      return;
    }

    intro.textContent = this.dataset.intro || '';
    selectPagesButton.textContent = this.dataset.selectPagesLabel || 'Select page';
    this.#insertedUids = new Set();
    this.#insertedLabels = [];
    selectPagesButton.addEventListener('click', () => this.#browsePages(textarea, pagesSpinner, pagesStatus));
    textarea.placeholder = this.dataset.placeholder || '';
    analyzeButton.textContent = this.dataset.analyzeLabel || 'Analyze';
    loading.textContent = this.dataset.loadingLabel || '';
    panel.querySelector('[data-role="tone-label"]').textContent = this.dataset.toneLabel || 'Tone';
    panel.querySelector('[data-role="examples-label"]').textContent = this.dataset.examplesLabel || 'Examples';

    const copyLabel = this.dataset.copyLabel || 'Copy';
    const copiedLabel = this.dataset.copiedLabel || 'Copied!';
    for (const [role, getText] of [
      ['copy-tone', () => toneEl.textContent],
      ['copy-examples', () => examplesEl.textContent],
    ]) {
      const button = panel.querySelector(`[data-role="${role}"]`);
      button.textContent = copyLabel;
      button.addEventListener('click', () => this.#copy(button, getText(), copyLabel, copiedLabel));
    }

    analyzeButton.addEventListener('click', () => {
      this.#analyze(textarea, analyzeButton, loading, errorEl, resultEl, toneEl, examplesEl);
    });

    Modal.advanced({
      type: Modal.types.default,
      size: Modal.sizes.medium,
      title: this.modalTitle,
      content: panel,
    });
  }

  async #analyze(textarea, analyzeButton, loading, errorEl, resultEl, toneEl, examplesEl) {
    const text = textarea.value.trim();
    errorEl.hidden = true;
    resultEl.hidden = true;
    if (!text) return;

    analyzeButton.disabled = true;
    loading.hidden = false;
    try {
      const response = await new AjaxRequest(this.url).post({ text });
      const data = await response.resolve();
      if (data.ok) {
        toneEl.textContent = data.tone;
        examplesEl.textContent = data.examples;
        resultEl.hidden = false;
      } else {
        errorEl.textContent = data.message || this.dataset.errorGeneric || 'Analysis failed.';
        errorEl.hidden = false;
      }
    } catch {
      errorEl.textContent = this.dataset.errorGeneric || 'The request failed. Please try again.';
      errorEl.hidden = false;
    }
    loading.hidden = true;
    analyzeButton.disabled = false;
  }

  #browsePages(textarea, spinnerEl, statusEl) {
    const browseUrl = this.dataset.browseUrl;
    if (!browseUrl) return;

    const modal = Modal.advanced({
      type: Modal.types.iframe,
      size: Modal.sizes.large,
      title: this.dataset.selectPagesTitle || '',
      content: browseUrl,
    });

    modal.addEventListener('typo3:element-browser:message', (e) => {
      const detail = e.detail || {};
      if (detail.actionName !== 'typo3:elementBrowser:elementAdded') return;

      modal.hideModal();

      const uid = parseInt(String(detail.value).split('_').pop(), 10);
      if (!uid || this.#insertedUids.has(uid)) return;
      this.#insertedUids.add(uid);

      this.#insertPageContent(uid, detail.label || '', textarea, spinnerEl, statusEl);
    });
  }

  // #insertedLabels accumulates across every "Select page" click within
  // this one open calibration modal, so the status line can list
  // everything inserted so far, not just the most recent page.
  async #insertPageContent(uid, label, textarea, spinnerEl, statusEl) {
    spinnerEl.hidden = false;
    statusEl.textContent = this.dataset.pagesExtractingLabel || '';
    try {
      const response = await new AjaxRequest(this.dataset.extractUrl).post({ pageUids: [uid] });
      const data = await response.resolve();
      if (data.ok) {
        const existing = textarea.value.trim();
        textarea.value = existing ? `${existing}\n\n${data.text}` : data.text;
        if (label) this.#insertedLabels.push(label);
        let statusText = this.#insertedLabels.length
          ? `${this.dataset.pagesInsertedLabel || ''} ${this.#insertedLabels.join(', ')}`.trim()
          : this.dataset.pagesInsertedLabel || '';
        // Visible, permanent answer to "is the frontend render actually
        // being used, or did this silently fall back to stored fields?",
        // rather than requiring a log dive every time to find out.
        const source = data.sources ? data.sources[String(uid)] : undefined;
        if (source === 'rendered' && this.dataset.sourceRenderedLabel) {
          statusText += ` (${this.dataset.sourceRenderedLabel})`;
        } else if (source === 'fallback' && this.dataset.sourceFallbackLabel) {
          statusText += ` (${this.dataset.sourceFallbackLabel})`;
        }
        statusEl.textContent = statusText;
      } else {
        statusEl.textContent = data.message || this.dataset.pagesNoContentLabel || '';
      }
    } catch {
      statusEl.textContent = this.dataset.errorGeneric || '';
    }
    spinnerEl.hidden = true;
  }

  // Only flips the button to "Copied!" once copyTextToClipboard() actually
  // resolves, rather than assuming success the moment it's called.
  #copy(button, text, copyLabel, copiedLabel) {
    if (!text) return;
    copyTextToClipboard(button, text).then(() => {
      button.textContent = copiedLabel;
      setTimeout(() => {
        button.textContent = copyLabel;
      }, 1500);
    }).catch(() => {
      // Leave the button's label alone; nothing was actually copied.
    });
  }
}

window.customElements.define('aim-calibrate-voice-trigger', AimCalibrateVoiceTrigger);
