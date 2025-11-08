<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');

function ok(array $data = []): never {
    http_response_code(200);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['a'] ?? $_POST['a'] ?? '';

ensure_issue();

switch ($action) {

    case 'ping':
        ok(['ts' => time()]);
        break;

    case 'getIssueHtml':
        $html = (string)($_SESSION['issue']['html'] ?? '');
        ok(['html' => $html]);
        break;

    case 'setAuthorsCount':
        if ($method !== 'POST') fail('POST required');
        $n  = (int)($_POST['n'] ?? 1);
        $ac = max(1, (int)($_POST['authors_count'] ?? 1));
        ensure_article_index($n);

        $_SESSION['issue']['articles'][$n]['authors_count'] = $ac;
        $arr = $_SESSION['issue']['articles'][$n]['authors'] ?? [];
        $arr = array_values($arr);
        $cur = count($arr);
        if ($ac > $cur) {
            for ($i = $cur; $i < $ac; $i++) {
                $arr[$i] = ['name'=>'','email'=>'','affiliation'=>'','orcid'=>''];
            }
        } else {
            $arr = array_slice($arr, 0, $ac);
        }
        $_SESSION['issue']['articles'][$n]['authors'] = $arr;
        ok(['authors' => $arr, 'count' => $ac]);
        break;

    case 'saveField':
        // Универсальное сохранение одного поля статьи: a=saveField&n=1&path=title_ru  body: value=...
        if ($method !== 'POST') fail('POST required');
        $n    = (int)($_POST['n'] ?? 1);
        $path = trim($_POST['path'] ?? '');
        $val  = (string)($_POST['value'] ?? '');
        if ($path === '') fail('Empty path');
        ensure_article_index($n);
        // Разрешённые пути в статье:
        $allowed = [
            'title_ru','title_en','abstract_ru','abstract_en','doi','pages','pub_date','references_raw'
        ];
        if (!in_array($path, $allowed, true)) {
            fail('Path not allowed: ' . $path);
        }
        $_SESSION['issue']['articles'][$n][$path] = $val;
        ok(['saved' => [$path => $val], 'n' => $n]);
        break;

    case 'saveAuthorField':
        // Сохранение поля автора: a=saveAuthorField  n=1  idx=0  field=name|email|affiliation|orcid  value=...
        if ($method !== 'POST') fail('POST required');
        $n     = (int)($_POST['n'] ?? 1);
        $idx   = max(0, (int)($_POST['idx'] ?? 0));
        $field = (string)($_POST['field'] ?? '');
        $val   = (string)($_POST['value'] ?? '');
        ensure_article_index($n);

        $allowed = ['name','email','affiliation','orcid'];
        if (!in_array($field, $allowed, true)) fail('Field not allowed');

        $count = (int)($_SESSION['issue']['articles'][$n]['authors_count'] ?? 1);
        $authors = $_SESSION['issue']['articles'][$n]['authors'] ?? [];
        for ($i = count($authors); $i < $count; $i++) {
            $authors[$i] = ['name'=>'','email'=>'','affiliation'=>'','orcid'=>''];
        }
        if (!isset($authors[$idx])) {
            fail('Author index out of range', 400, ['count' => $count]);
        }
        $authors[$idx][$field] = $val;
        $_SESSION['issue']['articles'][$n]['authors'] = $authors;
        ok(['author' => $authors[$idx], 'idx' => $idx]);
        break;

    case 'extractRefsFromHtml':
        // Черновой парсер ссылок из загруженного HTML выпуска по простому селектору.
        // Параметры: css=... (напр. ".article-details-references-value")
        if ($method !== 'POST') fail('POST required');
        $css = (string)($_POST['css'] ?? '');
        $n   = (int)($_POST['n'] ?? 1);
        if ($css === '') fail('CSS selector is empty');
        $html = (string)($_SESSION['issue']['html'] ?? '');
        if ($html === '') fail('Issue HTML is empty');

        // Очень простой «парсер»: ищем все блоки с data- или class/ id через регэксп (без полноценного CSS)
        // Под продакшн лучше поставить DOM+XPath, но здесь — быстрый эвристический вариант.
        $lines = [];

        // Попытаемся вытащить содержимое всех <div ...>...</div> по вхождению класса/идентификатора
        $needle = preg_quote($css, '/');
        // Упрощение: поддержим селекторы вида ".class", "#id" или "div.class"
        if ($css[0] === '.') {
            $re = '/<[^>]*class=["\'][^"\']*' . substr($needle, 2) . '[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/isu';
        } elseif ($css[0] === '#') {
            $re = '/<[^>]*id=["\']' . substr($needle, 2) . '["\'][^>]*>(.*?)<\/[^>]+>/isu';
        } else {
            // Общее вхождение как подстрока в открывающем теге
            $re = '/<[^>]*' . $needle . '[^>]*>(.*?)<\/[^>]+>/isu';
        }

        if (preg_match_all($re, $html, $m)) {
            foreach ($m[1] as $chunk) {
                // Заменим <br> и </p> на переводы строк, выпилим теги
                $chunk = preg_replace('/<(br|br\/)\s*>/isu', "\n", $chunk);
                $chunk = preg_replace('/<\/p\s*>/isu', "\n", $chunk);
                $text  = trim(strip_tags($chunk));
                $parts = preg_split('/\r\n|\r|\n/u', $text) ?: [];
                foreach ($parts as $line) {
                    $line = trim($line);
                    if ($line !== '') $lines[] = $line;
                }
            }
        }

        if (empty($lines)) {
            fail('Nothing matched by selector');
        }

        // Сохраним в статью n
        ensure_article_index($n);
        $_SESSION['issue']['articles'][$n]['references_raw'] = implode("\n", $lines);
        ok(['count' => count($lines)]);
        break;

    default:
        fail('Unknown action: ' . $action, 404);
}

