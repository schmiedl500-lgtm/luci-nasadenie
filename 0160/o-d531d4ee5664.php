<?php
// L.U.C.I – jednorazová obnova prístupu správcu firmy (stratený PIN aj kód firmy). © 2026 ETTA STEEL s. r. o.
// Správca si vyberie meno a zadá nový PIN; stránka vydá nový kód firmy, ukáže ho raz a sama sa zmaže. Po 24 hodinách sa zmaže tiež.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function koniec(string $t, bool $zmaz = false): void
{
    if ($zmaz) @unlink(__FILE__);
    echo '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>L.U.C.I – obnova prístupu</title>'
        . '<style>body{font:17px/1.5 system-ui,sans-serif;max-width:460px;margin:24px auto;padding:0 16px;background:#0e2a3d;color:#eef3f7}label{display:block;margin-top:14px;font-size:14px;color:#aab7c3}input,select{width:100%;box-sizing:border-box;padding:12px;font-size:19px;border-radius:10px;border:1px solid #33506a;background:#153a54;color:#fff}button{margin-top:18px;width:100%;padding:14px;font-size:17px;font-weight:700;border:0;border-radius:10px;background:#EE6A12;color:#fff}.k{font-size:30px;font-weight:700;letter-spacing:.12em;user-select:all;margin:12px 0}.m{color:#aab7c3;font-size:14px}.z{background:#7a1f1f;padding:10px 12px;border-radius:10px}</style></head><body><h2>L.U.C.I – obnova prístupu správcu</h2>'
        . $t . '</body></html>';
    exit;
}
if (time() - (int)@filemtime(__FILE__) > 86400) koniec('<p>Platnosť tejto stránky vypršala.</p>', true);
$cfg = null; $dir = '';
foreach ([dirname(__DIR__, 3) . '/luci-data/config.php', __DIR__ . '/data/config.php'] as $c) if (@is_file($c)) { $cfg = require $c; $dir = dirname($c); break; }
if (!is_array($cfg) || strlen((string)($cfg['sol'] ?? '')) < 32 || isset($cfg['dsn'])) koniec('<p>Server nie je nastavený tak, ako táto stránka čaká.</p>');
try {
    $p = new PDO('sqlite:' . $dir . '/luci.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('PRAGMA busy_timeout=5000');
    $sp = $p->query("SELECT c.id, c.firma_id, c.meno, f.nazov FROM clovek c JOIN firma f ON f.id = c.firma_id WHERE c.rola = 'spravca' AND c.aktivny = 1 AND f.stav = 'aktivna' ORDER BY f.nazov, c.meno")->fetchAll();
} catch (Throwable $x) {
    koniec('<p>Databáza sa nedá otvoriť. Nič sa nezmenilo.</p>');
}
if (!$sp) koniec('<p>Na serveri nie je žiadny aktívny správca firmy.</p>');
function formular(array $sp, string $chyba = ''): void
{
    $o = '';
    foreach ($sp as $c) $o .= '<option value="' . e($c['id']) . '">' . e($c['meno'] . ' – ' . $c['nazov']) . '</option>';
    koniec(($chyba ? '<p class="z">' . e($chyba) . '</p>' : '') . '<p>Vyber svoje meno a zadaj si nový PIN. Starý PIN a starý kód firmy prestanú platiť. Ostatným ľuďom sa nič nemení.</p><form method="post"><label>Kto si (správca firmy)</label><select name="kto">' . (count($sp) > 1 ? '<option value="">vyber svoje meno</option>' : '') . $o . '</select><label>Nový PIN (6 až 10 číslic)</label><input name="pin" type="password" inputmode="numeric" autocomplete="new-password"><label>Nový PIN ešte raz</label><input name="pin2" type="password" inputmode="numeric" autocomplete="new-password"><button>Nastaviť PIN a vydať nový kód firmy</button></form><p class="m">Stránka sa po použití sama zmaže.</p>');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') formular($sp);
$kto = null;
foreach ($sp as $c) if (is_string($_POST['kto'] ?? null) && hash_equals($c['id'], $_POST['kto'])) $kto = $c;
$pin = is_string($_POST['pin'] ?? null) ? trim($_POST['pin']) : '';
if (!$kto) formular($sp, 'Vyber svoje meno.');
if (!preg_match('/^\d{6,10}$/', $pin)) formular($sp, 'PIN musí mať 6 až 10 číslic.');
if (preg_match('/^(\d)\1+$/', $pin) || in_array($pin, ['123456', '654321', '1234567', '12345678', '123123'], true)) formular($sp, 'Tento PIN je príliš jednoduchý, zvoľ iný.');
if ($pin !== (is_string($_POST['pin2'] ?? null) ? trim($_POST['pin2']) : '')) formular($sp, 'PIN-y sa nezhodujú.');
try {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $kod = '';
    for ($i = 0; $i < 10; $i++) $kod .= $a[random_int(0, strlen($a) - 1)];
    $id = substr(strtr(base64_encode(random_bytes(18)), '+/', 'ab'), 0, 22);
    $p->beginTransaction();
    $p->prepare('UPDATE clovek SET pin_h = ?, zle = 0, blok_do = 0 WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $kto['id']]);
    $p->prepare('DELETE FROM kluc WHERE clovek_id = ?')->execute([$kto['id']]);   // staré prihlásenia správcu končia, ako pri každej zmene PIN-u
    $p->prepare('UPDATE firma SET kod_h = ? WHERE id = ?')->execute([hash_hmac('sha256', 'firma|' . $kod, $cfg['sol']), $kto['firma_id']]);
    $p->prepare('INSERT INTO zapis (id, firma_id, kto_id, co, vec_id, detail, cas) VALUES (?,?,?,?,?,?,?)')->execute([$id, $kto['firma_id'], $kto['id'], 'kod_firmy_novy', $kto['firma_id'], json_encode(['ako' => 'obnova prístupu správcu (jednorazová stránka), nový PIN'], JSON_UNESCAPED_UNICODE), time()]);
    $p->commit();
} catch (Throwable $x) {
    if ($p->inTransaction()) $p->rollBack();
    koniec('<p>Nepodarilo sa: chyba databázy. Nič sa nezmenilo.</p>');
}
koniec('<p>Hotovo. Firma: <b>' . e($kto['nazov']) . '</b> · správca: ' . e($kto['meno']) . '</p><p>Nový kód firmy:</p><div class="k">' . $kod . '</div><p>Zapíš si ho teraz, už sa neukáže. Nie je v ňom písmeno O ani I, ani číslica 0 ani 1.</p><p>Prihlásenie: tento kód → tvoje meno → nový PIN.</p><p class="m">Táto stránka sa práve zmazala.</p>', true);
