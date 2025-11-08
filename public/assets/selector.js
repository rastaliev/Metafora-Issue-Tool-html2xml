// Лёгкий инструмент захвата выделенного текста из iframe с предпросмотром HTML выпуска.
// Горячие клавиши:
//   - В iframe:   Ctrl+Shift+C  — сохранить текущее выделение как «issueSelectedText» (+ в буфер обмена)
//   - В форме:    Ctrl+Shift+V  — вставить сохранённый «issueSelectedText» в активное поле ввода

(function () {
  'use strict';

  // Глобальный слот для сохранённого выделения
  window.__issueSelectedText = '';

  function getIframeDoc() {
    const iframe = document.getElementById('issueFrame') || document.querySelector('iframe');
    if (!iframe) return null;
    try {
      return iframe.contentDocument || iframe.contentWindow.document || null;
    } catch (e) {
      return null; // sandbox без allow-same-origin или cross-origin
    }
  }

  function copyToClipboard(text) {
    if (!text) return;
    try {
      navigator.clipboard && navigator.clipboard.writeText(text);
    } catch (e) {
      // молча
    }
  }

  function normalizeText(s) {
    if (!s) return '';
    // Уберём множественные пробелы и мягкие переносы
    return s.replace(/\u00AD/g, '').replace(/\s+\n/g, '\n').replace(/\n\s+/g, '\n').replace(/[ \t]{2,}/g, ' ').trim();
  }

  // Внутри iframe: ловим Ctrl+Shift+C
  function bindInsideIframe() {
    const doc = getIframeDoc();
    if (!doc) return;
    // На всякий случай — вешаем заново при перезаливке srcdoc
    doc.addEventListener('keydown', (ev) => {
      if (ev.ctrlKey && ev.shiftKey && (ev.code === 'KeyC' || ev.key === 'C')) {
        const sel = doc.getSelection ? doc.getSelection().toString() : '';
        const text = normalizeText(sel);
        if (text) {
          window.__issueSelectedText = text;
          copyToClipboard(text);
          // Небольшое визуальное подтверждение
          try {
            const toast = doc.createElement('div');
            toast.textContent = '✓ Текст сохранён';
            Object.assign(toast.style, {
              position: 'fixed',
              bottom: '12px',
              right: '12px',
              background: 'rgba(0,0,0,.75)',
              color: '#fff',
              padding: '8px 10px',
              borderRadius: '8px',
              fontFamily: 'system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial',
              fontSize: '12px',
              zIndex: 2147483647
            });
            doc.body.appendChild(toast);
            setTimeout(() => toast.remove(), 1000);
          } catch (e) {}
        }
        ev.preventDefault();
        ev.stopPropagation();
      }
    }, true);
  }

  // Снаружи (на форме): Ctrl+Shift+V вставляет сохранённый текст
  function bindOutsidePaste() {
    document.addEventListener('keydown', (ev) => {
      if (ev.ctrlKey && ev.shiftKey && (ev.code === 'KeyV' || ev.key === 'V')) {
        const el = document.activeElement;
        if (!el) return;
        const text = window.__issueSelectedText || '';
        if (!text) return;

        // Поддержка <input>, <textarea> и contenteditable
        const isInput = el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && /^(text|search|email|url|tel|date|number|password)?$/i.test(el.type || 'text'));
        if (isInput) {
          const start = el.selectionStart ?? el.value.length;
          const end = el.selectionEnd ?? el.value.length;
          const before = el.value.slice(0, start);
          const after  = el.value.slice(end);
          el.value = before + text + after;
          const pos = before.length + text.length;
          try { el.setSelectionRange(pos, pos); } catch (e) {}
          ev.preventDefault();
          ev.stopPropagation();
          return;
        }
        if (el.isContentEditable) {
          try {
            const sel = window.getSelection();
            if (sel && sel.rangeCount > 0) {
              sel.deleteFromDocument();
              sel.getRangeAt(0).insertNode(document.createTextNode(text));
            } else {
              el.textContent += text;
            }
          } catch (e) {
            // fallback
            el.textContent += text;
          }
          ev.preventDefault();
          ev.stopPropagation();
        }
      }
    }, true);
  }

  // Когда iframe готов — привязываем обработчики внутри него
  function waitIframeReady() {
    const iframe = document.getElementById('issueFrame') || document.querySelector('iframe');
    if (!iframe) return;
    // Пробуем сразу
    try { bindInsideIframe(); } catch (e) {}

    // И при каждом обновлении srcdoc/загрузке
    iframe.addEventListener('load', () => {
      try { bindInsideIframe(); } catch (e) {}
    });
  }

  // Инициализация
  function init() {
    waitIframeReady();
    bindOutsidePaste();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

