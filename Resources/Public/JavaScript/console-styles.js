/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

let consoleStylesPromise = null;

function loadConsoleStyles() {
  if (consoleStylesPromise) return consoleStylesPromise;
  const url = new URL('../Css/base.css', import.meta.url);
  url.searchParams.set('bust', Date.now().toString());
  consoleStylesPromise = fetch(url).then((r) => r.text());
  return consoleStylesPromise;
}

/**
 * Prepends a <style> tag containing base.css's content into `container`.
 * Fire-and-forget by design at call sites that already show the modal
 * before this resolves. A cold-cache first open may paint unstyled for a
 * moment rather than delaying the modal's normally-instant open.
 */
export function injectConsoleStyles(container) {
  return loadConsoleStyles().then((css) => {
    const style = document.createElement('style');
    style.textContent = css;
    container.prepend(style);
  });
}
