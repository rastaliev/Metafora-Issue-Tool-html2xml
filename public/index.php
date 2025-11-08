<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
ensure_issue();

/* =================== AJAX endpoints: autosave + validate =================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');

  // Автосохранение полной формы статьи (то же, что submit save_article, но без редиректа)
  if ($_POST['ajax'] === 'autosave-annotate') {
    $n = isset($_POST['n']) ? max(1, (int)$_POST['n']) : 1;
    ensure_article_index($n);
    $total = (int)$_SESSION['issue']['articles_total'];

    $_SESSION['issue']['articles'][$n]['doi']         = trim($_POST['doi'] ?? '');
    $_SESSION['issue']['articles'][$n]['edn']         = trim($_POST['edn'] ?? '');
    $_SESSION['issue']['articles'][$n]['title_ru']    = trim($_POST['title_ru'] ?? '');
    $_SESSION['issue']['articles'][$n]['title_en']    = trim($_POST['title_en'] ?? '');
    $_SESSION['issue']['articles'][$n]['abstract_ru'] = trim($_POST['abstract_ru'] ?? '');
    $_SESSION['issue']['articles'][$n]['abstract_en'] = trim($_POST['abstract_en'] ?? '');
    $_SESSION['issue']['articles'][$n]['kw_ru']       = trim($_POST['kw_ru'] ?? '');
    $_SESSION['issue']['articles'][$n]['kw_en']       = trim($_POST['kw_en'] ?? '');
    $_SESSION['issue']['articles'][$n]['page_first']  = trim($_POST['page_first'] ?? '');
    $_SESSION['issue']['articles'][$n]['page_last']   = trim($_POST['page_last'] ?? '');
    $_SESSION['issue']['articles'][$n]['pub_date']    = trim($_POST['pub_date'] ?? '');
    $authors_cnt = max(1, (int)($_POST['authors_count'] ?? 1));
    $_SESSION['issue']['articles'][$n]['authors_count'] = $authors_cnt;

    $fields = ['surname_ru','namepatronymic_ru','surname_en','initials_en','email','orcid','affiliation_ru','affiliation_en'];
    $authors = [];
    for ($i=0; $i<$authors_cnt; $i++) {
      $au = [];
      foreach ($fields as $f) {
        $arr = $_POST["au_{$f}"] ?? [];
        $au[$f] = trim($arr[$i] ?? '');
      }
      $authors[$i] = $au;
    }
    $_SESSION['issue']['articles'][$n]['authors'] = $authors;
    $_SESSION['issue']['articles'][$n]['references_raw'] = trim($_POST['references_raw'] ?? '');

    echo json_encode(['ok'=>true]); exit;
  }

  // Валидация: собрать текущий XML и проверить по XSD
  if ($_POST['ajax'] === 'validate-issue') {
    $issue = $_SESSION['issue'] ?? [];
    $xml   = build_issue_xml($issue);

    // кандидаты путей до схемы
    $candidates = [
      __DIR__ . '/schema/journal3.xsd',
      __DIR__ . '/schema/metafora.xsd',
      __DIR__ . '/../schema.xsd',
      __DIR__ . '/schema.xsd',
    ];
    $schemaPath = null;
    foreach ($candidates as $c) { if (is_file($c)) { $schemaPath = $c; break; } }

    if (!$schemaPath) {
      echo json_encode(['ok'=>false, 'msg'=>'Не найдена XSD-схема (schema/metafora.xsd или schema.xsd).']); exit;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml);
    if (!$loaded) {
      $errs = array_map(fn($e)=>trim($e->message), libxml_get_errors());
      libxml_clear_errors();
      echo json_encode(['ok'=>false, 'msg'=>'Ошибка разбора XML', 'errors'=>$errs]); exit;
    }
    $valid = $dom->schemaValidate($schemaPath);
    $errs = array_map(fn($e)=>trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    echo json_encode([
      'ok'     => (bool)$valid,
      'schema' => basename($schemaPath),
      'size'   => strlen($xml),
      'errors' => $errs,
    ]);
    exit;
  }

  echo json_encode(['ok'=>false, 'msg'=>'unknown ajax']); exit;
}
/* ========================================================================== */

$step = $_GET['step'] ?? 'welcome';
$n    = isset($_GET['n']) ? max(1, (int)$_GET['n']) : 1;

function layout_header(string $title = 'Metafora Issue Tool'): void { ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- жёстко светлая тема -->
  <style>
    :root{ color-scheme: only light; }
    html, body{ background:#fff !important; color:#111 !important; }
  </style>
  <link rel="stylesheet" href="assets/style.css?v=7">
  <style>
    .layout2col{
      display:grid;
      grid-template-columns: 1fr 1.15fr; /* правая колонка шире */
      gap:16px; width:100%; align-items:start;
    }
    @media (max-width: 1200px){ .layout2col{ grid-template-columns:1fr; } }
    .wrap{ max-width:none; width:100%; }
    .card{ overflow: visible !important; }

    /* Блок предпросмотра — обычный, фиксацию делает JS */
    .preview-box{
      border:1px solid var(--line);
      border-radius:10px;
      background:#fff;
      box-shadow: var(--shadow);
      overflow:auto;
      min-height:480px;
      height: calc(100vh - 130px); /* когда зафиксирован */
    }
    .preview-box iframe{
      width:100%; height:100%; border:0; display:block; background:#fff;
    }

    .label-row{ display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .paste-btn{
      white-space:nowrap; padding:6px 10px; border-radius:999px; border:1px solid var(--accent);
      background:#fff; color:var(--accent); cursor:pointer;
    }
    .paste-btn:hover{ background:#11111110; }

    /* Навигация по статьям */
    .article-nav{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .article-nav a{
      display:inline-block; text-decoration:none; padding:6px 10px; border-radius:999px;
      border:1px solid var(--line); background:#fff; color:inherit;
    }
    .article-nav a.active{ background:var(--accent); color:#fff; border-color:var(--accent); }

    /* Индикатор автосохранения */
    .save-indicator{ display:inline-flex; align-items:center; gap:6px; margin-left:8px; color:var(--muted); font-size:12px; }
    .dot{ width:8px; height:8px; border-radius:50%; background:#d1d5db; }
    .dot.ok{ background:#16a34a; }
  </style>

  <script>
    // Выбор текста из iframe предпросмотра
    function getPreviewSelection(){
      const ifr = document.getElementById('issueFrame');
      if(!ifr || !ifr.contentWindow) return '';
      try{
        const sel = ifr.contentWindow.getSelection();
        return sel ? String(sel).trim() : '';
      }catch(e){ return ''; }
    }
    // Вставка в поле
    function pasteFromPreview(id){
      const el = document.getElementById(id);
      if(!el) return false;
      const text = getPreviewSelection();
      if(!text){ alert('Сначала выделите текст в правом окне.'); return false; }
      if ('value' in el){
        const start = el.selectionStart ?? el.value.length;
        const end   = el.selectionEnd ?? el.value.length;
        el.value = el.value.slice(0,start) + text + el.value.slice(end);
        const pos = start + text.length;
        try{ el.setSelectionRange(pos,pos);}catch(e){}
        el.dispatchEvent(new Event('input', {bubbles:true})); // триггерим автосохранение
        el.focus();
      } else {
        el.textContent += text;
      }
      return false;
    }

    // Надёжная «прилипалка» предпросмотра
    function initPreviewFix(){
      const col = document.getElementById('previewCol');
      const box = document.getElementById('previewBox');
      if(!col || !box) return;

      const topOffset = 84;
      let colTop=0,colLeft=0,colWidth=0;

      function measure(){
        const rect = col.getBoundingClientRect();
        colTop = window.scrollY + rect.top;
        colLeft = window.scrollX + rect.left;
        colWidth = rect.width;
      }
      function apply(){
        const shouldFix = window.scrollY + topOffset > colTop && window.innerWidth > 1200;
        if(shouldFix){
          box.style.position='fixed';
          box.style.top = topOffset+'px';
          box.style.left = colLeft+'px';
          box.style.width = colWidth+'px';
          box.style.height = (window.innerHeight - (topOffset + 46)) + 'px';
        }else{
          box.style.position='static';
          box.style.top=''; box.style.left=''; box.style.width='';
          box.style.height='calc(100vh - 130px)';
        }
      }
      function update(){ measure(); apply(); }
      update();
      window.addEventListener('resize', update);
      window.addEventListener('scroll', apply, {passive:true});
    }

    document.addEventListener('DOMContentLoaded', initPreviewFix);
  </script>
</head>
<body>
<header>
  <h1>Metafora Issue Tool</h1>
  <div class="btns" style="margin:0">
    <a class="btn" href="index.php?step=welcome">Главная</a>
    <a class="btn" href="reset.php">Сброс сессии</a>
  </div>
</header>
<div class="wrap">
<?php }

function layout_footer(): void { ?>
</div>
</body>
</html>
<?php }

if ($step === 'welcome') {
  layout_header('Добро пожаловать'); ?>
  <div class="card">
    <h2>Привет!</h2>
    <p>Мастер соберёт XML выпуска: загрузишь HTML номера, внесёшь метаданные и статьи, потом скачаешь готовый файл.</p>
    <div class="btns">
      <a class="btn primary" href="index.php?step=upload">Начать новую сессию</a>
      <a class="btn" href="reset.php">Сбросить сессию</a>
    </div>
  </div>
  <?php layout_footer(); exit;
}

if ($step === 'upload') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = '';
    if (!isset($_FILES['issue_html']) || $_FILES['issue_html']['error'] !== UPLOAD_ERR_OK) {
      $err = 'Не удалось получить файл.';
    } else {
      $tmp = $_FILES['issue_html']['tmp_name'];
      $html = @file_get_contents($tmp);
      if ($html === false || trim($html) === '') $err = 'Файл пустой или не читается.';
      else { $_SESSION['issue']['html'] = $html; header('Location: index.php?step=meta'); exit; }
    }
  }
  layout_header('Загрузка HTML выпуска'); ?>
  <div class="card">
    <h2>Загрузить HTML выпуска</h2>
    <?php if (!empty($err)): ?><p class="muted"><?=htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <label>Файл HTML (*.html, *.htm)</label>
      <input type="file" name="issue_html" accept=".html,.htm" required>
      <div class="btns">
        <button class="btn primary" type="submit">Загрузить</button>
        <a class="btn" href="index.php?step=welcome">Отмена</a>
      </div>
      <p class="hint">Выделяй текст в правом окне и жми кнопку «Спарсить» у нужного поля.</p>
    </form>
  </div>
  <?php layout_footer(); exit;
}

if ($step === 'meta') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['issue']['meta'] = [
      'journal_title_ru' => trim($_POST['journal_title_ru'] ?? ''),
      'journal_title_en' => trim($_POST['journal_title_en'] ?? ''),
      'issn'             => trim($_POST['issn'] ?? ''),
      'eissn'            => trim($_POST['eissn'] ?? ''),
      'volume'           => trim($_POST['volume'] ?? ''),
      'issue'            => trim($_POST['issue'] ?? ''),
      'year'             => trim($_POST['year'] ?? ''),
      'publisher'        => trim($_POST['publisher'] ?? ''),
    ];
    header('Location: index.php?step=count'); exit;
  }
  $m = $_SESSION['issue']['meta'];
  layout_header('Метаданные выпуска'); ?>
  <div class="card">
    <h2>Метаданные выпуска</h2>
    <form method="post">
      <div class="row">
        <div>
          <div class="label-row">
            <label>Название журнала (RU) *</label>
            <button class="paste-btn" onclick="return pasteFromPreview('journal_title_ru')">Спарсить</button>
          </div>
          <input id="journal_title_ru" type="text" name="journal_title_ru" required value="<?=htmlspecialchars($m['journal_title_ru'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
        <div>
          <div class="label-row">
            <label>Название журнала (EN)</label>
            <button class="paste-btn" onclick="return pasteFromPreview('journal_title_en')">Спарсить</button>
          </div>
          <input id="journal_title_en" type="text" name="journal_title_en" value="<?=htmlspecialchars($m['journal_title_en'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
      </div>
      <div class="row">
        <div>
          <label>ISSN</label>
          <input id="issn" type="text" name="issn" value="<?=htmlspecialchars($m['issn'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
        <div>
          <label>eISSN</label>
          <input id="eissn" type="text" name="eissn" value="<?=htmlspecialchars($m['eissn'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
      </div>
      <div class="row">
        <div>
          <label>Том</label>
          <input id="volume" type="text" name="volume" value="<?=htmlspecialchars($m['volume'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
        <div>
          <label>Номер</label>
          <input id="issue" type="text" name="issue" value="<?=htmlspecialchars($m['issue'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
      </div>
      <div class="row">
        <div>
          <label>Год</label>
          <input id="year" type="text" name="year" value="<?=htmlspecialchars($m['year'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
        <div>
          <div class="label-row">
            <label>Издатель</label>
            <button class="paste-btn" onclick="return pasteFromPreview('publisher')">Спарсить</button>
          </div>
          <input id="publisher" type="text" name="publisher" value="<?=htmlspecialchars($m['publisher'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        </div>
      </div>
      <div class="btns">
        <button class="btn primary" type="submit">Сохранить и далее</button>
        <a class="btn" href="index.php?step=welcome">Назад</a>
      </div>
    </form>
  </div>
  <?php layout_footer(); exit;
}

if ($step === 'count') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cnt = max(0, (int)$_POST['articles_total'] ?? 0);
    $_SESSION['issue']['articles_total'] = $cnt;
    $_SESSION['issue']['articles'] = [];
    header('Location: index.php?step=annotate&n=1'); exit;
  }
  layout_header('Количество статей'); ?>
  <div class="card">
    <h2>Сколько статей в выпуске?</h2>
    <form method="post">
      <label>Число статей *</label>
      <input type="number" name="articles_total" required min="0" value="<?=htmlspecialchars((string)($_SESSION['issue']['articles_total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
      <div class="btns">
        <button class="btn primary" type="submit">Далее</button>
        <a class="btn" href="index.php?step=meta">Назад</a>
      </div>
    </form>
  </div>
  <?php layout_footer(); exit;
}

if ($step === 'annotate') {
  ensure_article_index($n);
  $total = (int)$_SESSION['issue']['articles_total'];
  $a = $_SESSION['issue']['articles'][$n];

  if (isset($_POST['set_authors_count']) || isset($_POST['add_author']) || isset($_POST['remove_author'])) {
    $newCount = (int)($a['authors_count'] ?? 1);
    if (isset($_POST['add_author'])) $newCount++;
    elseif (isset($_POST['remove_author'])) $newCount--;
    else $newCount = max(1, (int)($_POST['authors_count'] ?? 1));
    $newCount = max(1, $newCount);

    $_SESSION['issue']['articles'][$n]['authors_count'] = $newCount;
    $arr = array_values($a['authors'] ?? []);
    $cur = count($arr);
    if ($newCount > $cur) {
      for ($i=$cur; $i<$newCount; $i++) {
        $arr[$i] = [
          'surname_ru'=> '', 'namepatronymic_ru'=> '',
          'surname_en'=> '', 'initials_en'=> '',
          'email'=> '', 'orcid'=> '',
          'affiliation_ru'=> '', 'affiliation_en'=> ''
        ];
      }
    } else {
      $arr = array_slice($arr, 0, $newCount);
    }
    $_SESSION['issue']['articles'][$n]['authors'] = $arr;
    header("Location: index.php?step=annotate&n={$n}"); exit;
  }

  if (isset($_POST['save_article'])) {
    $_SESSION['issue']['articles'][$n]['doi']         = trim($_POST['doi'] ?? '');
    $_SESSION['issue']['articles'][$n]['edn']         = trim($_POST['edn'] ?? '');
    $_SESSION['issue']['articles'][$n]['title_ru']    = trim($_POST['title_ru'] ?? '');
    $_SESSION['issue']['articles'][$n]['title_en']    = trim($_POST['title_en'] ?? '');
    $_SESSION['issue']['articles'][$n]['abstract_ru'] = trim($_POST['abstract_ru'] ?? '');
    $_SESSION['issue']['articles'][$n]['abstract_en'] = trim($_POST['abstract_en'] ?? '');
    $_SESSION['issue']['articles'][$n]['kw_ru']       = trim($_POST['kw_ru'] ?? '');
    $_SESSION['issue']['articles'][$n]['kw_en']       = trim($_POST['kw_en'] ?? '');
    $_SESSION['issue']['articles'][$n]['page_first']  = trim($_POST['page_first'] ?? '');
    $_SESSION['issue']['articles'][$n]['page_last']   = trim($_POST['page_last'] ?? '');
    $_SESSION['issue']['articles'][$n]['pub_date']    = trim($_POST['pub_date'] ?? '');
    $authors_cnt = max(1, (int)($_POST['authors_count'] ?? 1));
    $_SESSION['issue']['articles'][$n]['authors_count'] = $authors_cnt;

    $fields = ['surname_ru','namepatronymic_ru','surname_en','initials_en','email','orcid','affiliation_ru','affiliation_en'];
    $authors = [];
    for ($i=0; $i<$authors_cnt; $i++) {
      $au = [];
      foreach ($fields as $f) {
        $arr = $_POST["au_{$f}"] ?? [];
        $au[$f] = trim($arr[$i] ?? '');
      }
      $authors[$i] = $au;
    }
    $_SESSION['issue']['articles'][$n]['authors'] = $authors;

    $_SESSION['issue']['articles'][$n]['references_raw'] = trim($_POST['references_raw'] ?? '');

    if (isset($_POST['next'])) {
      $next = $n + 1;
      header('Location: ' . ($next <= $total ? "index.php?step=annotate&n={$next}" : 'index.php?step=generate')); exit;
    } else {
      header("Location: index.php?step=annotate&n={$n}"); exit;
    }
  }

  $a = $_SESSION['issue']['articles'][$n];
  layout_header("Статья №{$n} из {$total}"); ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
      <h2 style="margin:0;">Статья <?=htmlspecialchars((string)$n, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?> / <?=htmlspecialchars((string)$total, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></h2>
      <div class="article-nav">
        <?php for ($i=1; $i<=$total; $i++): ?>
          <a class="<?= $i===$n ? 'active' : '' ?>" href="index.php?step=annotate&n=<?=$i?>">Статья <?=$i?></a>
        <?php endfor; ?>
        <span class="save-indicator"><span id="saveDot" class="dot"></span><span id="saveText">изменения не сохранены</span></span>
      </div>
    </div>
    <p class="hint">Выделите фрагмент справа и нажмите «Спарсить» возле нужного поля.</p>

    <div class="layout2col">
      <!-- Левая колонка -->
      <div>
        <form id="articleForm" method="post">
          <input type="hidden" name="authors_count" value="<?=htmlspecialchars((string)($a['authors_count'] ?? 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">

          <div class="row">
            <div>
              <div class="label-row">
                <label>DOI</label>
                <button class="paste-btn" onclick="return pasteFromPreview('doi')">Спарсить</button>
              </div>
              <input id="doi" type="text" name="doi" value="<?=htmlspecialchars($a['doi'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
            </div>
            <div>
              <div class="label-row">
                <label>EDN</label>
                <button class="paste-btn" onclick="return pasteFromPreview('edn')">Спарсить</button>
              </div>
              <input id="edn" type="text" name="edn" value="<?=htmlspecialchars($a['edn'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
            </div>
          </div>

          <div class="label-row">
            <label>Заголовок (RU) *</label>
            <button class="paste-btn" onclick="return pasteFromPreview('title_ru')">Спарсить</button>
          </div>
          <input id="title_ru" type="text" name="title_ru" required value="<?=htmlspecialchars($a['title_ru'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">

          <div class="label-row">
            <label>Заголовок (EN) *</label>
            <button class="paste-btn" onclick="return pasteFromPreview('title_en')">Спарсить</button>
          </div>
          <input id="title_en" type="text" name="title_en" required value="<?=htmlspecialchars($a['title_en'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">

          <div class="label-row">
            <label>Аннотация (RU) *</label>
            <button class="paste-btn" onclick="return pasteFromPreview('abstract_ru')">Спарсить</button>
          </div>
          <textarea id="abstract_ru" name="abstract_ru"><?=htmlspecialchars($a['abstract_ru'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></textarea>

          <div class="label-row">
            <label>Аннотация (EN) *</label>
            <button class="paste-btn" onclick="return pasteFromPreview('abstract_en')">Спарсить</button>
          </div>
          <textarea id="abstract_en" name="abstract_en"><?=htmlspecialchars($a['abstract_en'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></textarea>

          <div class="row">
            <div>
              <div class="label-row">
                <label>Ключевые слова (RU) *</label>
                <button class="paste-btn" onclick="return pasteFromPreview('kw_ru')">Спарсить</button>
              </div>
              <textarea id="kw_ru" name="kw_ru" placeholder="через запятую или точку с запятой"><?=htmlspecialchars($a['kw_ru'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></textarea>
            </div>
            <div>
              <div class="label-row">
                <label>Ключевые слова (EN) *</label>
                <button class="paste-btn" onclick="return pasteFromPreview('kw_en')">Спарсить</button>
              </div>
              <textarea id="kw_en" name="kw_en" placeholder="comma- or semicolon-separated"><?=htmlspecialchars($a['kw_en'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></textarea>
            </div>
          </div>

          <div class="row">
            <div>
              <label>Первая страница *</label>
              <input id="page_first" type="text" name="page_first" value="<?=htmlspecialchars($a['page_first'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
            </div>
            <div>
              <label>Последняя страница *</label>
              <input id="page_last" type="text" name="page_last" value="<?=htmlspecialchars($a['page_last'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
            </div>
          </div>

          <label>Дата публикации (YYYY-MM-DD) *</label>
          <input id="pub_date" type="date" name="pub_date" value="<?=htmlspecialchars($a['pub_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">

          <div class="divider"></div>

          <div class="row">
            <div>
              <label>Число авторов *</label>
              <input id="authors_count_vis" type="number" min="1" value="<?=htmlspecialchars((string)($a['authors_count'] ?? 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"
                     oninput="document.querySelector('input[name=authors_count]').value=this.value">
            </div>
            <div style="align-self:end; display:flex; gap:8px;">
              <button class="btn" name="set_authors_count" value="1">Применить</button>
              <button class="btn" name="add_author" value="1">Добавить автора</button>
              <button class="btn" name="remove_author" value="1">Убрать автора</button>
            </div>
          </div>

          <?php
            $auth = $a['authors'] ?? [];
            $cnt = (int)($a['authors_count'] ?? 1);
            for ($i = 0; $i < $cnt; $i++):
              $au = $auth[$i] ?? [];
              $idx = $i+1;
          ?>
            <div class="card">
              <strong>Автор <?=$idx?></strong>

              <div class="row">
                <div>
                  <div class="label-row">
                    <label>Фамилия (RU) *</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_surname_ru_<?=$idx?>')">Спарсить</button>
                  </div>
                  <input id="au_surname_ru_<?=$idx?>" type="text" name="au_surname_ru[]" value="<?=htmlspecialchars($au['surname_ru'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
                <div>
                  <div class="label-row">
                    <label>Имя Отчество (RU) *</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_namepatronymic_ru_<?=$idx?>')">Спарсить</button>
                  </div>
                  <input id="au_namepatronymic_ru_<?=$idx?>" type="text" name="au_namepatronymic_ru[]" value="<?=htmlspecialchars($au['namepatronymic_ru'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
              </div>

              <div class="row">
                <div>
                  <div class="label-row">
                    <label>Surname (EN) *</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_surname_en_<?=$idx?>')">Спарсить</button>
                  </div>
                    <input id="au_surname_en_<?=$idx?>" type="text" name="au_surname_en[]" value="<?=htmlspecialchars($au['surname_en'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
                <div>
                  <div class="label-row">
                    <label>Initials (EN) *</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_initials_en_<?=$idx?>')">Спарсить</button>
                  </div>
                  <input id="au_initials_en_<?=$idx?>" type="text" name="au_initials_en[]" value="<?=htmlspecialchars($au['initials_en'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
              </div>

              <div class="row">
                <div>
                  <div class="label-row">
                    <label>Email *</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_email_<?=$idx?>')">Спарсить</button>
                  </div>
                  <input id="au_email_<?=$idx?>" type="email" name="au_email[]" value="<?=htmlspecialchars($au['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
                <div>
                  <div class="label-row">
                    <label>ORCID</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_orcid_<?=$idx?>')">Спарсить</button>
                  </div>
                  <input id="au_orcid_<?=$idx?>" type="text" name="au_orcid[]" value="<?=htmlspecialchars($au['orcid'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
              </div>

              <div class="row">
                <div>
                  <div class="label-row">
                    <label>Аффилиация (RU) *</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_aff_ru_<?=$idx?>')">Спарсить</button>
                  </div>
                  <input id="au_aff_ru_<?=$idx?>" type="text" name="au_affiliation_ru[]" value="<?=htmlspecialchars($au['affiliation_ru'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
                <div>
                  <div class="label-row">
                    <label>Affiliation (EN) *</label>
                    <button class="paste-btn" onclick="return pasteFromPreview('au_aff_en_<?=$idx?>')">Спарсить</button>
                  </div>
                  <input id="au_aff_en_<?=$idx?>" type="text" name="au_affiliation_en[]" value="<?=htmlspecialchars($au['affiliation_en'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                </div>
              </div>
            </div>
          <?php endfor; ?>

          <div class="divider"></div>

          <div class="label-row">
            <label>Список литературы (по одному источнику на строку)</label>
            <button class="paste-btn" onclick="return pasteFromPreview('references_raw')">Спарсить</button>
          </div>
          <textarea id="references_raw" name="references_raw" placeholder="Каждая запись — с новой строки"><?=htmlspecialchars($a['references_raw'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></textarea>

          <div class="btns">
            <button class="btn" type="submit" name="save_article" value="1">Сохранить</button>
            <button class="btn primary" type="submit" name="save_article" value="1" onclick="this.form.insertAdjacentHTML('beforeend','<input type=&quot;hidden&quot; name=&quot;next&quot; value=&quot;1&quot;>')">Сохранить и далее</button>
            <a class="btn" href="index.php?step=generate">К генерации</a>
          </div>

          <div class="btns" style="margin-top:10px; justify-content:space-between;">
            <button type="button" class="btn" id="schemaValidateBtn">Валидация по схеме</button>
            <span class="hint">Автосохранение включено</span>
          </div>
        </form>
      </div>

      <!-- Правая колонка: фиксируем через JS -->
      <div id="previewCol">
        <label>Предпросмотр загруженного HTML выпуска</label>
        <?php if (!empty($_SESSION['issue']['html'])): ?>
          <div id="previewBox" class="preview-box">
            <iframe id="issueFrame" sandbox="allow-same-origin allow-top-navigation-by-user-activation"
                    srcdoc="<?=htmlspecialchars($_SESSION['issue']['html'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"></iframe>
          </div>
        <?php else: ?>
          <div class="card"><span class="muted">HTML выпуска ещё не загружен. Вернись к шагу «Загрузка HTML».</span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    // ===== Автосохранение формы статьи =====
    (function(){
      const form = document.getElementById('articleForm');
      if(!form) return;

      const dot = document.getElementById('saveDot');
      const txt = document.getElementById('saveText');
      let timer = null, inflight = false;

      function markSaving(){
        if(dot) dot.classList.remove('ok');
        if(txt) txt.textContent = 'сохранение...';
      }
      function markSaved(){
        if(dot) dot.classList.add('ok');
        if(txt) txt.textContent = 'сохранено';
      }
      function markDirty(){
        if(dot) dot.classList.remove('ok');
        if(txt) txt.textContent = 'изменения не сохранены';
      }

      function send(){
        if(inflight) return;
        inflight = true;
        markSaving();

        const fd = new FormData(form);
        fd.set('ajax', 'autosave-annotate');
        fd.set('n', '<?= (int)$n ?>');

        fetch('index.php', {method:'POST', body:fd})
          .then(r=>r.json()).then(j=>{
            if(j && j.ok) markSaved(); else markDirty();
          })
          .catch(()=>markDirty())
          .finally(()=>{ inflight=false; });
      }

      form.addEventListener('input', ()=>{
        markDirty();
        clearTimeout(timer);
        timer = setTimeout(send, 600);
      });
      form.addEventListener('change', ()=>{
        markDirty();
        clearTimeout(timer);
        timer = setTimeout(send, 200);
      });
    })();

    // ===== Валидация по схеме =====
    (function(){
      const btn = document.getElementById('schemaValidateBtn');
      if(!btn) return;
      btn.addEventListener('click', ()=>{
        const fd = new FormData();
        fd.set('ajax','validate-issue');
        fetch('index.php', {method:'POST', body:fd})
          .then(r=>r.json())
          .then(j=>{
            if(!j){ alert('Ошибка связи'); return; }
            if(j.ok){
              alert('XML валиден по схеме '+ (j.schema||'') + ' ('+ (j.size||0) +' байт).');
            }else{
              let msg = 'XML не прошёл валидацию';
              if(j.schema) msg += ' по схеме '+j.schema;
              if(Array.isArray(j.errors) && j.errors.length){
                msg += ':\n\n' + j.errors.join('\n');
              }else if(j.msg){ msg += ':\n\n' + j.msg; }
              alert(msg);
            }
          })
          .catch(()=> alert('Не удалось выполнить валидацию.'));
      });
    })();
  </script>

  <?php layout_footer(); exit;
}

if ($step === 'generate') {
  $issue = $_SESSION['issue'];
  $meta = $issue['meta'];
  $total = (int)$issue['articles_total'];
  layout_header('Генерация XML'); ?>
  <div class="card">
    <h2>Почти готово</h2>
    <table class="meta">
      <tr><td>Журнал (RU):</td><td><strong><?=htmlspecialchars($meta['journal_title_ru'] ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong></td></tr>
      <tr><td>Журнал (EN):</td><td><?=htmlspecialchars($meta['journal_title_en'] ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></td></tr>
      <tr><td>ISSN / eISSN:</td><td><?=htmlspecialchars(trim(($meta['issn'] ?: '').($meta['eissn'] ? ' / '.$meta['eissn'] : '')) ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></td></tr>
      <tr><td>Том / Номер / Год:</td><td><?=htmlspecialchars(trim(($meta['volume'] ?: '').' / '.($meta['issue'] ?: '').' / '.($meta['year'] ?: '')) ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></td></tr>
      <tr><td>Издатель:</td><td><?=htmlspecialchars($meta['publisher'] ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></td></tr>
      <tr><td>Статей:</td><td><strong><?=htmlspecialchars((string)$total, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong></td></tr>
    </table>
    <div class="btns">
      <a class="btn" href="index.php?step=annotate&n=1">К статьям</a>
      <a class="btn primary" href="index.php?step=download">Скачать XML</a>
    </div>
  </div>
  <?php layout_footer(); exit;
}

if ($step === 'download') {
  $issue = $_SESSION['issue'];
  $xml = build_issue_xml($issue);
  $fname = ($issue['meta']['journal_title_ru'] ?: 'journal') . '-' . ($issue['meta']['year'] ?: date('Y')) . '-' . ($issue['meta']['issue'] ?: 'issue') . '.xml';
  $fname = preg_replace('/[^\pL\pN_\-\.]+/u', '_', $fname) ?: 'issue.xml';
  header('Content-Type: application/xml; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  header('Content-Length: ' . strlen($xml));
  echo $xml; exit;
}

header('Location: index.php?step=welcome'); exit;
