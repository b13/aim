/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import Modal from '@typo3/backend/modal.js';

/**
 * Wraps a real `<a href>` (a normal, full-chrome link to a request-log
 * detail view) so a plain left-click opens the same detail in a Modal
 * instead of navigating away. Mirrors core's own
 * `typo3-backend-contextual-record-edit-trigger` pattern (view detail
 * without losing the calling page's context, e.g. a filtered/paginated
 * list, or another extension's own review listing), but keeps the inner
 * anchor real rather than replacing it entirely: middle-click, ctrl/cmd/
 * shift-click ("open in new tab"), and no-JS fallback all keep working
 * exactly like an ordinary link, since the element only ever intercepts a
 * plain primary-button click.
 *
 * `modal-url` points at a chrome-stripped, aim-console-styled fragment
 * (see `RequestLogController::showContextualAction()`) fetched via
 * Modal.types.ajax , so the detail lands in the modal's own padded
 * body with the console styling applied, rather than a fully separate
 * document with none of that. The inner `<a href>` keeps pointing at
 * the normal, full-chrome page throughout.
 *
 * Usage:
 * ```html
 * <aim-request-log-trigger modal-url="{f:be.uri(route: 'aim_request_log.showContextual', parameters: '{uid: uid}')}" modal-title="{f:translate(...)}">
 *     <a href="{f:be.uri(route: 'aim_request_log.show', parameters: '{uid: uid}')}">...</a>
 * </aim-request-log-trigger>
 * ```
 */
class AimRequestLogTrigger extends HTMLElement {
  #abortController;
  #link;

  connectedCallback() {
    this.#abortController = new AbortController();
    this.#link = this.querySelector('a[href]');
    if (!this.#link) return;

    this.#link.addEventListener('click', this.#handleClick, { signal: this.#abortController.signal });
  }

  disconnectedCallback() {
    this.#abortController?.abort();
  }

  get modalUrl() {
    return this.getAttribute('modal-url');
  }

  get modalTitle() {
    return this.getAttribute('modal-title') || '';
  }

  #handleClick = (e) => {
    if (!this.modalUrl) return;
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    Modal.advanced({
      type: Modal.types.ajax,
      size: Modal.sizes.large,
      title: this.modalTitle,
      content: this.modalUrl,
    });
  };
}

window.customElements.define('aim-request-log-trigger', AimRequestLogTrigger);
