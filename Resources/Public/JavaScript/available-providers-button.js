/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import Modal from '@typo3/backend/modal.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class AimAvailableProviders extends HTMLElement {
  #abortController;
  #button;

  connectedCallback() {
    this.#abortController = new AbortController();
    this.#button = this.querySelector('button');
    if (!this.#button) return;

    this.#button.addEventListener('click', this.#handleClick, {
      signal: this.#abortController.signal,
    });
  }

  disconnectedCallback() {
    this.#abortController?.abort();
  }

  get url() {
    return this.getAttribute('url');
  }

  get modalTitle() {
    return this.getAttribute('modal-title') || 'Available Providers';
  }

  #handleClick = (e) => {
    e.preventDefault();
    this.#openModal();
  };

  #openModal() {
    let changed = false;
    const modal = Modal.advanced({
      type: Modal.types.ajax,
      size: Modal.sizes.large,
      title: this.modalTitle,
      content: this.url,
      ajaxCallback: (root) => this.#initModelToggles(root, () => { changed = true; }),
    });
    modal.addEventListener('typo3-modal-hidden', () => {
      if (changed) {
        document.querySelector('.t3js-module-body iframe')?.contentWindow?.location.reload() || window.location.reload();
      }
    });
  }

  #initModelToggles(root, onChanged) {
    const container = root.querySelector('[data-toggle-url]');
    if (!container) return;
    const toggleUrl = container.dataset.toggleUrl;

    for (const btn of container.querySelectorAll('[data-model-toggle]')) {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          const response = await new AjaxRequest(toggleUrl)
            .post({ provider: btn.dataset.provider, model: btn.dataset.model });
          const data = await response.resolve();
          btn.classList.toggle('badge-notice', data.disabled);
          btn.classList.toggle('badge-success', !data.disabled);
          onChanged();
        } catch (e) {
          console.error('Model toggle failed:', e);
        }
        btn.disabled = false;
      });
    }
  }
}

window.customElements.define('aim-available-providers', AimAvailableProviders);
