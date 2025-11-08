// Общие мелочи для UX: авто-сохранение одного поля через API по Alt+S,
// подсказка про горячие клавиши и быстрая проверка API.

(function () {
  'use strict';

  function ping() {
    fetch('api.php?a=ping')
      .then(r => r.json())
      .then(j => {
        // молча, просто проверка связности
        if (!j || !j.ok) throw new Error('API ping failed');
      })
      .catch(() => {});
  }

  function toast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    Object.assign(t.style, {
      position: 'fixed', bottom: '12px', left: '12px',
      background: 'rgba(17,17,17,.9)', color: '#fff',
      padding: '8px 10px', borderRadius: '8px', fontSize: '12px',
      zIndex: 2147483647, fontFamily: 'system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial'
    });
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 1200);
  }

  function bindAltSave() {
    // Alt+S: сохранить текущее поле статьи в фоне (если оно в форме статьи)
    document.addEventListener('keydown', (ev) => {
      if (ev.altKey && (ev.code === 'KeyS' || ev.key === 's' || ev.key === 'S')) {
        const el = document.activeElement;
        if (!el) return;
        // Определим контекст «статьи» по наличию параметра ?step=annotate&n=...
        const params = new URLSearchParams(location.search);
        if (params.get('step') !== 'annotate') return;

        const n = parseInt(params.get('n') || '1', 10);

        // Карта полей формы -> path в API saveField
        const map = {
          title_ru: 'title_ru',
          title_en: 'title_en',
          abstract_ru: 'abstract_ru',
          abstract_en: 'abstract_en',
          doi: 'doi',
          pages: 'pages',
          pub_date: 'pub_date',
          references_raw: 'references_raw'
        };

        if (el.name && map[el.name]) {
          const path = map[el.name];
          const value = (el.value != null) ? el.value : (el.textContent || '');
          fetch('api.php?a=saveField', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({ n: String(n), path, value })
          }).then(r => r.json()).then(j => {
            if (j && j.ok) toast('✓ Сохранено');
            else throw 0;
          }).catch(() => toast('Не удалось сохранить'));
          ev.preventDefault();
          ev.stopPropagation();
        }
      }
    }, true);
  }

  function tipHotkeys() {
    // Подсказка на экране аннотирования (один раз)
    const params = new URLSearchParams(location.search);
    if (params.get('step') === 'annotate' && !sessionStorage.getItem('hotkeysTipShown')) {
      toast('Подсказка: в предпросмотре Ctrl+Shift+C, в поле Ctrl+Shift+V');
      sessionStorage.setItem('hotkeysTipShown', '1');
    }
  }

  function init() {
    ping();
    bindAltSave();
    tipHotkeys();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

