<?php
declare(strict_types=1);

/**
 * Сессия, утилиты, структура данных и генерация XML по journal3.xsd.
 */
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
session_name('metafora_issue_tool');
session_start();

/* ---------- Утилиты ---------- */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url, true, 302);
    exit;
}

function ensure_issue(): void {
    if (!isset($_SESSION['issue']) || !is_array($_SESSION['issue'])) {
        $_SESSION['issue'] = [
            'html' => '',
            'meta' => [
                'journal_title_ru' => '',
                'journal_title_en' => '',
                'issn'             => '',
                'eissn'            => '',
                'volume'           => '',
                'issue'            => '',
                'year'             => '',
                'publisher'        => '',
            ],
            'articles_total' => 0,
            'articles'       => []
        ];
    }
}

function ensure_article_index(int $n): void {
    ensure_issue();
    $total = (int)($_SESSION['issue']['articles_total'] ?? 0);
    if ($total && ($n < 1 || $n > $total)) redirect('index.php?step=annotate&n=1');

    if (!isset($_SESSION['issue']['articles'][$n])) {
        $_SESSION['issue']['articles'][$n] = [
            'doi'         => '',
            'edn'         => '',
            'title_ru'    => '',
            'title_en'    => '',
            'abstract_ru' => '',
            'abstract_en' => '',
            'kw_ru'       => '',
            'kw_en'       => '',
            'page_first'  => '',
            'page_last'   => '',
            'pub_date'    => '', // YYYY-MM-DD
            'authors_count' => 1,
            'authors'     => [],
            'references_raw' => '',
        ];
    }
    if (!isset($_SESSION['issue']['articles'][$n]['authors'][0])) {
        $_SESSION['issue']['articles'][$n]['authors'][0] = [
            'surname_ru'        => '',
            'namepatronymic_ru' => '',
            'surname_en'        => '',
            'initials_en'       => '',
            'email'             => '',
            'orcid'             => '',
            'affiliation_ru'    => '',
            'affiliation_en'    => '',
        ];
    }
}

/* ---------- Генерация XML по твоей схеме ---------- */

function build_issue_xml(array $issue): string {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $journal = $dom->createElement('journal');
    $dom->appendChild($journal);

    // operCard
    $oper = $dom->createElement('operCard');
    $oper->appendChild($dom->createElement('date', date('Y-m-d')));
    $oper->appendChild($dom->createElement('cntArticle', (string)($issue['articles_total'] ?? 0)));
    $oper->appendChild($dom->createElement('cs', '0'));
    $oper->appendChild($dom->createElement('operator', ''));
    $journal->appendChild($oper);

    // issn/eissn
    if (!empty($issue['meta']['issn']))  $journal->appendChild($dom->createElement('issn',  $issue['meta']['issn']));
    if (!empty($issue['meta']['eissn'])) $journal->appendChild($dom->createElement('eissn', $issue['meta']['eissn']));

    // journalInfo
    $ji = $dom->createElement('journalInfo');
    $ji->setAttribute('lang', 'ru');
    $ji->appendChild($dom->createElement('title',  $issue['meta']['journal_title_ru'] ?? ''));
    $ji->appendChild($dom->createElement('Title',  $issue['meta']['journal_title_en'] ?? ''));
    $ji->appendChild($dom->createElement('abbrTitle', ''));
    $ji->appendChild($dom->createElement('publ',   $issue['meta']['publisher'] ?? ''));
    $ji->appendChild($dom->createElement('placePubl', ''));
    $ji->appendChild($dom->createElement('address',  ''));
    $journal->appendChild($ji);

    // issue
    $issueNode = $dom->createElement('issue');
    $issueNode->appendChild($dom->createElement('volume',  $issue['meta']['volume'] ?? ''));
    $issueNode->appendChild($dom->createElement('number',  $issue['meta']['issue']  ?? ''));
    $issueNode->appendChild($dom->createElement('altNumber', ''));
    $issueNode->appendChild($dom->createElement('dateUni', $issue['meta']['year']   ?? ''));
    $issueNode->appendChild($dom->createElement('part', ''));
    $issueNode->appendChild($dom->createElement('pages', ''));
    $issTitle = $dom->createElement('issTitle', '');
    $issTitle->setAttribute('lang', 'ru');
    $issueNode->appendChild($issTitle);

    // articles
    $articlesNode = $dom->createElement('articles');

    $total = (int)($issue['articles_total'] ?? 0);
    for ($i = 1; $i <= $total; $i++) {
        $a = $issue['articles'][$i] ?? null;
        if (!$a) continue;

        $art = $dom->createElement('article');

        // codes
        if (!empty($a['doi']) || !empty($a['edn'])) {
            $codes = $dom->createElement('codes');
            if (!empty($a['doi'])) $codes->appendChild($dom->createElement('doi', $a['doi']));
            if (!empty($a['edn'])) $codes->appendChild($dom->createElement('edn', $a['edn']));
            $art->appendChild($codes);
        }

        // titles
        $titles = $dom->createElement('artTitles');
        if (!empty($a['title_ru'])) {
            $t = $dom->createElement('artTitle');
            $t->setAttribute('lang', 'ru');
            $t->appendChild($dom->createTextNode($a['title_ru']));
            $titles->appendChild($t);
        }
        if (!empty($a['title_en'])) {
            $t = $dom->createElement('artTitle');
            $t->setAttribute('lang', 'en');
            $t->appendChild($dom->createTextNode($a['title_en']));
            $titles->appendChild($t);
        }
        $art->appendChild($titles);

        // abstracts
        if (!empty($a['abstract_ru']) || !empty($a['abstract_en'])) {
            $abs = $dom->createElement('abstracts');
            if (!empty($a['abstract_ru'])) {
                $ar = $dom->createElement('abstract');
                $ar->setAttribute('lang', 'ru');
                $ar->appendChild($dom->createTextNode($a['abstract_ru']));
                $abs->appendChild($ar);
            }
            if (!empty($a['abstract_en'])) {
                $ae = $dom->createElement('abstract');
                $ae->setAttribute('lang', 'en');
                $ae->appendChild($dom->createTextNode($a['abstract_en']));
                $abs->appendChild($ae);
            }
            $art->appendChild($abs);
        }

        // keywords
        if (!empty($a['kw_ru']) || !empty($a['kw_en'])) {
            $kw = $dom->createElement('keywords');
            if (!empty($a['kw_ru'])) {
                $grp = $dom->createElement('kwdGroup');
                $grp->setAttribute('lang', 'ru');
                foreach (preg_split('/[;,]\s*|\r\n|\r|\n/u', (string)$a['kw_ru']) ?: [] as $w) {
                    $w = trim($w);
                    if ($w !== '') $grp->appendChild($dom->createElement('keyword', $w));
                }
                $kw->appendChild($grp);
            }
            if (!empty($a['kw_en'])) {
                $grp = $dom->createElement('kwdGroup');
                $grp->setAttribute('lang', 'en');
                foreach (preg_split('/[;,]\s*|\r\n|\r|\n/u', (string)$a['kw_en']) ?: [] as $w) {
                    $w = trim($w);
                    if ($w !== '') $grp->appendChild($dom->createElement('keyword', $w));
                }
                $kw->appendChild($grp);
            }
            $art->appendChild($kw);
        }

        // pages
        $pf = trim((string)($a['page_first'] ?? ''));
        $pl = trim((string)($a['page_last']  ?? ''));
        if ($pf !== '' || $pl !== '') {
            $pagesStr = ($pf !== '' && $pl !== '') ? ($pf.'-'.$pl) : ($pf.$pl);
            $art->appendChild($dom->createElement('pages', $pagesStr));
        }

        // dates
        if (!empty($a['pub_date'])) {
            $dates = $dom->createElement('dates');
            $dates->appendChild($dom->createElement('datePublication', $a['pub_date']));
            $art->appendChild($dates);
        }

        // authors
        $authorsNode = $dom->createElement('authors');
        $count = (int)($a['authors_count'] ?? 0);
        $arr = $a['authors'] ?? [];
        for ($k = 0; $k < $count; $k++) {
            $au = $arr[$k] ?? [];
            $author = $dom->createElement('author');

            // individInfo RU (обязателен lang)
            $ru = $dom->createElement('individInfo');
            $ru->setAttribute('lang', 'ru');
            $ru->appendChild($dom->createElement('surname', $au['surname_ru'] ?? ''));
            $ru->appendChild($dom->createElement('initials', $au['namepatronymic_ru'] ?? ''));
            if (!empty($au['affiliation_ru'])) {
                $org = $dom->createElement('orgName');
                $org->appendChild($dom->createTextNode($au['affiliation_ru']));
                $ru->appendChild($org);
            }
            // email положим сюда, если нет EN email
            $emailPlaced = false;

            // individInfo EN
            $en = $dom->createElement('individInfo');
            $en->setAttribute('lang', 'en');
            $en->appendChild($dom->createElement('surname', $au['surname_en'] ?? ''));
            $en->appendChild($dom->createElement('initials', $au['initials_en'] ?? ''));
            if (!empty($au['affiliation_en'])) {
                $org2 = $dom->createElement('orgName');
                $org2->appendChild($dom->createTextNode($au['affiliation_en']));
                $en->appendChild($org2);
            }
            // email — внутри individInfo (EN, если указан)
            if (!empty($au['email'])) {
                $em = $dom->createElement('email');
                $em->appendChild($dom->createTextNode($au['email']));
                $en->appendChild($em);
                $emailPlaced = true;
            }

            // если email не положили в EN — кладём в RU
            if (!$emailPlaced && !empty($au['email'])) {
                $em = $dom->createElement('email');
                $em->appendChild($dom->createTextNode($au['email']));
                $ru->appendChild($em);
            }

            $author->appendChild($ru);
            $author->appendChild($en);

            // authorCodes → orcid (на уровне author)
            if (!empty($au['orcid'])) {
                $codes = $dom->createElement('authorCodes');
                $codes->appendChild($dom->createElement('orcid', $au['orcid']));
                $author->appendChild($codes);
            }

            $authorsNode->appendChild($author);
        }
        $art->appendChild($authorsNode);

        // references
        $refsRaw = (string)($a['references_raw'] ?? '');
        if (trim($refsRaw) !== '') {
            $refs = $dom->createElement('references');
            $lines = preg_split('/\r\n|\r|\n/u', $refsRaw) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $ref = $dom->createElement('reference');
                $ri  = $dom->createElement('refInfo');
                $ri->setAttribute('lang', 'ru');
                $txt = $dom->createElement('text');
                $txt->appendChild($dom->createTextNode($line));
                $ri->appendChild($txt);
                $ref->appendChild($ri);
                $refs->appendChild($ref);
            }
            $art->appendChild($refs);
        }

        $articlesNode->appendChild($art);
    }

    $issueNode->appendChild($articlesNode);
    $journal->appendChild($issueNode);

    return $dom->saveXML();
}
