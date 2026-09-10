/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Icons from '@typo3/backend/icons.js';

const TONES = {
  checking: 'info',
  connected: 'good',
  disconnected: 'critical',
  failed: 'critical',
};

class AimVerifyProvider extends HTMLElement {
  #abortController;
  #button;
  #statusCell;

  connectedCallback() {
    this.#abortController = new AbortController();
    this.#button = this.querySelector('button');
    if (!this.#button) return;

    this.#statusCell = document.querySelector(`[data-verify-status="${this.uid}"]`);
    this.#button.addEventListener('click', this.#handleClick, {
      signal: this.#abortController.signal,
    });
  }

  disconnectedCallback() {
    this.#abortController?.abort();
  }

  get uid() {
    return this.getAttribute('uid');
  }

  #handleClick = () => this.#verify();

  async #verify() {
    this.#button.disabled = true;
    const originalHtml = this.#button.innerHTML;

    Icons.getIcon('spinner-circle', Icons.sizes.small).then((spinner) => {
      this.#button.innerHTML = spinner;
    });

    if (this.#statusCell) {
      this.#renderStatus(TONES.checking, 'checking\u2026');
    }

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.aim_verify_provider).post({ uid: this.uid });
      const data = await response.resolve();

      if (data.ok) {
        Notification.success('Provider connected', data.message, 5);
      } else {
        Notification.error('Provider disconnected', data.message, 10);
      }

      if (this.#statusCell) {
        this.#renderStatus(
          data.ok ? TONES.connected : TONES.disconnected,
          data.ok ? 'connected' : 'disconnected',
          data.message ?? '',
          data.checkedAt ? new Date(data.checkedAt * 1000).toLocaleString() : '',
        );
      }
    } catch (e) {
      Notification.error('Verification failed', e.message, 10);
      if (this.#statusCell) {
        this.#renderStatus(TONES.failed, 'error', e.message ?? '');
      }
    }

    this.#button.innerHTML = originalHtml;
    this.#button.disabled = false;
  }

  #renderStatus(tone, text, title = '', time = '') {
    const chip = document.createElement('span');
    chip.className = 'aim-chip';
    chip.dataset.tone = tone;
    chip.textContent = text;
    if (title !== '') {
      chip.title = title;
    }

    this.#statusCell.replaceChildren(chip);
    if (time !== '') {
      this.#statusCell.append(
        document.createElement('br'),
        Object.assign(document.createElement('small'), {
          className: 'text-body-secondary',
          textContent: time,
        }),
      );
    }
  }
}

window.customElements.define('aim-verify-provider', AimVerifyProvider);
