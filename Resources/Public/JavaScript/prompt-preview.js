/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import Modal from '@typo3/backend/modal.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { ModuleStateStorage } from '@typo3/backend/storage/module-state-storage.js';
import { injectConsoleStyles } from '@b13/aim/console-styles.js';

const moduleState = document.getElementById('pp-module-state');
if (moduleState?.dataset.hasExplicitPageId === '0') {
  const { identifier } = ModuleStateStorage.current('web');
  if (identifier) {
    const url = new URL(window.location.href);
    url.searchParams.set('id', identifier);
    window.location.replace(url.toString());
  }
}

const LAYER_LABEL_DATASET_KEYS = {
  pageTone: 'labelPageTone',
  globalFallback: 'labelGlobalFallback',
  userFragments: 'labelUserFragments',
  registryFragments: 'labelRegistryFragments',
  providerAddendum: 'labelProviderAddendum',
};

const LAYER_RAIL_VARS = {
  pageTone: ['--aim-gold', '--aim-gold-glow'],
  globalFallback: ['--aim-gold', '--aim-gold-glow'],
  userFragments: ['--aim-magenta', '--aim-magenta-glow'],
  registryFragments: ['--aim-cyan', '--aim-cyan-glow'],
  providerAddendum: ['--aim-violet', '--aim-violet-glow'],
};

/**
 * The element itself is the clickable trigger. It carries its own `btn`
 * classes directly (rather than wrapping an inner `<button>`), so it can sit
 * as a direct child of a Bootstrap `.btn-group` and receive that component's
 * adjacent-button border-radius/spacing styling, which only ever targets
 * direct children. Not a native `<button>`, so `tabindex`/`role` and
 * Enter/Space activation are added here to keep it keyboard-accessible.
 *
 * Opens the composed-prompt breakdown in a modal. `target` (an element id)
 * always points at a `<template>` — never a live, already-rendered panel —
 * so a fresh, disposable copy of the panel markup is cloned into the modal
 * on every open. Used both for a page row's template and the "preview the
 * currently tree-selected page" template in the notice bar; the exact same
 * mechanism works in either context since the template is always referenced
 * explicitly, never located by relative DOM position.
 */
class AimPromptPreviewToggle extends HTMLElement {
  #abortController;
  #template;
  #panel;
  #scopeSelect;
  #providerSelect;

  connectedCallback() {
    this.#abortController = new AbortController();
    const { signal } = this.#abortController;

    const targetId = this.getAttribute('target');
    this.#template = targetId ? document.getElementById(targetId) : null;
    if (!this.#template) return;

    if (!this.hasAttribute('tabindex')) this.setAttribute('tabindex', '0');
    if (!this.hasAttribute('role')) this.setAttribute('role', 'button');

    this.addEventListener('click', this.#handleOpen, { signal });
    this.addEventListener('keydown', this.#handleKeydown, { signal });
  }

  disconnectedCallback() {
    this.#abortController?.abort();
  }

  #handleKeydown = (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    this.#handleOpen();
  };

  #handleOpen = () => {
    this.#panel = document.createElement('div');
    for (const [key, value] of Object.entries(this.#template.dataset)) {
      this.#panel.dataset[key] = value;
    }
    this.#panel.append(this.#template.content.cloneNode(true));

    // Fire-and-forget: the modal already opens below without waiting on
    // this, so the console styling attaches a moment after first paint on
    // a cold cache (imperceptible in practice) rather than delaying the
    // "instant open" feel every other preview trigger already has.
    injectConsoleStyles(this.#panel);

    this.#scopeSelect = this.#panel.querySelector('[data-role="scope"]');
    this.#providerSelect = this.#panel.querySelector('[data-role="provider"]');
    this.#scopeSelect?.addEventListener('change', this.#handleRefresh);
    this.#providerSelect?.addEventListener('change', this.#handleRefresh);

    Modal.advanced({
      type: Modal.types.default,
      size: Modal.sizes.large,
      title: this.#panel.dataset.modalTitle || '',
      content: this.#panel,
    });

    this.#refresh();
  };

  #handleRefresh = () => this.#refresh();

  async #refresh() {
    const errorBox = this.#panel.querySelector('[data-role="error"]');
    const loadingBox = this.#panel.querySelector('[data-role="loading"]');
    const layersBox = this.#panel.querySelector('[data-role="layers"]');
    errorBox.hidden = true;
    loadingBox.hidden = false;
    layersBox.replaceChildren();
    this.#scopeSelect.disabled = true;
    this.#providerSelect.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.aim_prompt_preview).post({
        pageId: this.#panel.dataset.pageId,
        scope: this.#scopeSelect.value,
        providerConfigurationUid: this.#providerSelect.value,
      });
      const data = await response.resolve();

      if (!data.ok) {
        errorBox.textContent = data.message;
        errorBox.hidden = false;
        return;
      }
      this.#renderResult(data);
    } catch (e) {
      errorBox.textContent = e.message;
      errorBox.hidden = false;
    } finally {
      loadingBox.hidden = true;
      this.#scopeSelect.disabled = false;
      this.#providerSelect.disabled = false;
    }
  }

  #renderResult(data) {
    const emptyLabel = this.#panel.dataset.labelEmpty;
    const charactersLabel = this.#panel.dataset.labelCharacters;
    const tokensLabel = this.#panel.dataset.labelTokens;

    const layersBox = this.#panel.querySelector('[data-role="layers"]');
    layersBox.replaceChildren(
      ...data.layers.map((layer) => this.#renderLayer(layer, data.totalCharacterCount, emptyLabel, charactersLabel)),
    );

    const composedHeading = this.#panel.querySelector('[data-role="composed-heading"]');
    composedHeading.textContent = this.#scopeSelect.value === 'imageGeneration'
      ? composedHeading.dataset.labelImageGeneration
      : composedHeading.dataset.labelSystemPrompt;

    this.#panel.querySelector('[data-role="composed"]').textContent = data.composed || emptyLabel;
    this.#panel.querySelector('[data-role="stats"]').textContent =
      `${data.totalCharacterCount} ${charactersLabel} · ${data.approximateTokenCount} ${tokensLabel}`;
  }

  #renderLayer(layer, totalCharacterCount, emptyLabel, charactersLabel) {
    const [railVar, glowVar] = LAYER_RAIL_VARS[layer.labelKey] ?? LAYER_RAIL_VARS.pageTone;
    const label = this.#panel.dataset[LAYER_LABEL_DATASET_KEYS[layer.labelKey]] ?? layer.labelKey;
    const meterPercent = totalCharacterCount > 0
      ? Math.min(100, Math.round((layer.characterCount / totalCharacterCount) * 100))
      : 0;

    const channel = document.createElement('div');
    channel.className = 'aim-channel';
    channel.style.setProperty('--rail-color', `var(${railVar})`);
    channel.style.setProperty('--rail-glow', `var(${glowVar})`);

    const labelCol = document.createElement('div');
    labelCol.className = 'aim-channel__label-col';

    const name = document.createElement('span');
    name.className = 'aim-channel__name';
    name.textContent = label;

    const meter = document.createElement('div');
    meter.className = 'aim-channel__meter';
    const meterFill = document.createElement('span');
    meterFill.style.width = `${meterPercent}%`;
    meter.append(meterFill);

    const count = document.createElement('span');
    count.className = 'aim-channel__count';
    count.textContent = `${layer.characterCount} ${charactersLabel}`;

    labelCol.append(name, meter, count);

    const contentCol = document.createElement('div');
    contentCol.className = 'aim-channel__content-col';

    if (layer.parts.length === 0) {
      channel.classList.add('aim-channel--empty');
      const empty = document.createElement('p');
      empty.textContent = emptyLabel;
      contentCol.append(empty);
    } else {
      layer.parts.forEach((part) => {
        const p = document.createElement('p');
        p.textContent = part;
        contentCol.append(p);
      });
    }

    channel.append(labelCol, contentCol);
    return channel;
  }
}

window.customElements.define('aim-prompt-preview-toggle', AimPromptPreviewToggle);
