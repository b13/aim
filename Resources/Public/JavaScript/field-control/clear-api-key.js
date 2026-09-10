/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

import DocumentService from '@typo3/core/document-service.js';
import FormEngine from '@typo3/backend/form-engine.js';
import Icons from '@typo3/backend/icons.js';

/**
 * Marks the stored API key for deletion on the next save.
 *
 * A `type => password` field renders two inputs: a visible one carrying
 * `data-formengine-input-name`, and a hidden one carrying the actual `name`,
 * which starts out disabled until the field is modified.
 *
 * The marker goes into the hidden field directly rather than through the
 * visible one. Writing to the visible field would work, but FormEngine then
 * echoes the value back obfuscated, so the field fills up with asterisks and
 * reads as "a key was entered" when the opposite is about to happen.
 *
 * Toggling rather than firing once: nothing is written until the form is saved,
 * so a second click has to be able to take it back, including the field's
 * "changed" marker.
 */
class ClearApiKey {
  constructor(controlElementId) {
    this.control = null;
    this.visibleField = null;
    this.hiddenField = null;
    this.note = null;
    this.idleIcon = null;
    this.originalPlaceholder = '';
    this.armed = false;

    DocumentService.ready().then(() => {
      this.control = document.getElementById(controlElementId);
      if (this.control === null) {
        return;
      }
      const itemName = this.control.dataset.itemName;
      this.visibleField = document.querySelector('input[data-formengine-input-name="' + itemName + '"]');
      this.hiddenField = document.querySelector('input[name="' + itemName + '"]');
      if (this.visibleField === null || this.hiddenField === null) {
        return;
      }
      this.idleIcon = this.control.innerHTML;
      this.originalPlaceholder = this.visibleField.placeholder || '';
      this.control.addEventListener('click', this.toggle.bind(this));
      // Typing a replacement is the other way to deal with an old key, and it
      // wins: the sync overwrites the hidden field anyway, so the armed state
      // would otherwise linger and lie.
      this.visibleField.addEventListener('input', () => {
        if (this.armed) {
          this.disarm();
        }
      });
    });
  }

  toggle(event) {
    event.preventDefault();
    this.armed ? this.disarm() : this.arm();
  }

  arm() {
    this.armed = true;
    this.hiddenField.disabled = false;
    this.hiddenField.value = this.control.dataset.marker;
    this.visibleField.value = '';
    this.visibleField.readOnly = true;
    this.visibleField.placeholder = this.control.dataset.placeholderPending;
    FormEngine.markFieldAsChanged(this.visibleField);
    this.showNote();
    this.setControlState(this.control.dataset.iconArmed, this.control.dataset.labelArmed, true);
  }

  disarm() {
    this.armed = false;
    this.hiddenField.value = '';
    this.hiddenField.disabled = true;
    this.visibleField.readOnly = false;
    this.visibleField.placeholder = this.originalPlaceholder;
    this.unmarkFieldAsChanged(this.visibleField);
    this.removeNote();
    this.setControlState(null, this.control.dataset.labelIdle, false);
  }

  /**
   * FormEngine has no inverse for markFieldAsChanged, which only adds the
   * has-change class to the field and to its palette label, so undo it the
   * same way.
   */
  unmarkFieldAsChanged(field) {
    field.classList.remove('has-change');
    field.closest('.t3js-formengine-palette-field')
      ?.querySelector('.t3js-formengine-label')
      ?.classList.remove('has-change');
  }

  setControlState(iconIdentifier, label, armed) {
    if (label) {
      this.control.title = label;
      this.control.setAttribute('aria-label', label);
    }
    this.control.setAttribute('aria-pressed', armed ? 'true' : 'false');
    if (iconIdentifier === null) {
      this.control.innerHTML = this.idleIcon;
      return;
    }
    Icons.getIcon(iconIdentifier, Icons.sizes.small).then((markup) => {
      if (this.armed !== armed) {
        return;
      }
      this.control.innerHTML = markup;
      this.control.title = label;
      this.control.setAttribute('aria-label', label);
    });
  }

  showNote() {
    if (this.note !== null) {
      return;
    }
    this.note = document.createElement('p');
    this.note.className = 'form-text text-warning';
    this.note.setAttribute('role', 'status');
    (this.visibleField.closest('.form-wizards-wrap') ?? this.visibleField.closest('.form-control-wrap'))
      ?.insertAdjacentElement('afterend', this.note);
    const note = this.note;
    requestAnimationFrame(() => {
      if (this.note === note) {
        note.textContent = this.control.dataset.labelPending;
      }
    });
  }

  removeNote() {
    this.note?.remove();
    this.note = null;
  }
}

export default ClearApiKey;
