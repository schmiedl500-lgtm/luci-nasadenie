<?php
// L.U.C.I – jednorazové vydanie nového kódu firmy správcom (keď sa kód stratil a správca nie je nikde prihlásený).
// © 2026 ETTA STEEL s. r. o. · Overí PIN správcu firmy, ukáže nový kód raz a sám sa zmaže. Po 5 zlých PIN-och alebo po 24 hodinách sa zmaže tiež.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
function koniec(string $t, bool $zmaz = false): void
{
    if ($zmaz) @unlink(__FILE__);
    echo '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>L.U.C.I – nový kód firmy</title>'
        . '<style>body{font:17px/1.5 system-ui,sans-serif;max-width:460px;margin:24px auto;padding:0 16px;background:#0e2a3d;color:#eef3f7}label{display:block;margin-top:14px;font-size:14px;color:#aab7c3}input{width:100%;box-sizing:border-box;padding:12px;font-size:20px;border-radius:10px;border:1px solid #33506a;background:#153a54;color:#fff}button{margin-top:18px;width:100%;padding:14px;font-size:17px;font-weight:700;border:0;border-radius:10px;background:#EE6A12;color:#fff}.k{font-size:30px;font-weight:700;letter-spacing:.12em;user-select:all;margin:12px 0}.m{color:#aab7c3;font-size:14px}</style></head><body><h2>L.U.C.I – nový kód firmy</h2>'
        . $t . '</body></html>';
    exit;
}
if (time() - (int)@filemtime(__FILE__) > 86400) koniec('<p>Platnosť tejto stránky vypršala.</p>', true);
$cfg = null;
foreach ([dirname(__DIR__, 3) . '/luci-data/config.php', __DIR__ . '/data/config.php'] as $c) if (@is_file($c)) { $cfg = require $c; $dir = dirname($c); break; }
if (!is_array($cfg) || strlen((string)($cfg['sol'] ?? '')) < 32 || isset($cfg['dsn'])) koniec('<p>Server nie je nastavený tak, ako táto stránka čaká.</p>');
$pocF = $dir . '/.novykod_pokusy';
$zle = (int)@file_get_contents($pocF);
if ($zle >= 5) { @unlink($pocF); koniec('<p>Príliš veľa zlých pokusov. Stránka sa zmazala.</p>', true); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    koniec('<p>Zadaj svoj PIN správcu firmy. Starý kód firmy hneď prestane platiť pre nové prihlásenia; už prihlásených ľudí to neodhlási.</p><form method="post"><label>PIN správcu firmy</label><input name="pin" type="password" inputmode="numeric" autocomplete="off" autofocus><button>Vydať nový kód firmy</button></form><p class="m">Zostáva pokusov: ' . (5 - $zle) . '</p>');
}
$pin = is_string($_POST['pin'] ?? null) ? trim($_POST['pin']) : '';
try {
    $p = new PDO('sqlite:' . $dir . '/luci.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('PRAGMA busy_timeout=5000');
    $kto = null;
    if (preg_match('/^\d{6,10}$/', $pin)) {
        foreach ($p->query("SELECT c.id, c.firma_id, c.pin_h, c.meno, f.nazov FROM clovek c JOIN firma f ON f.id = c.firma_id WHERE c.rola = 'spravca' AND c.aktivny = 1 AND f.stav = 'aktivna'")->fetchAll() as $c) {
            if (password_verify($pin, $c['pin_h'])) { $kto = $c; break; }
        }
    }
    if (!$kto) {
        @file_put_contents($pocF, (string)($zle + 1));
        if ($zle + 1 >= 5) { @unlink($pocF); koniec('<p>PIN nesedí. Príliš veľa zlých pokusov, stránka sa zmazala.</p>', true); }
        koniec('<p>PIN nesedí. Zostáva pokusov: ' . (4 - $zle) . '</p><p><a href="" style="color:#fff">Skúsiť znova</a></p>');
    }
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $kod = '';
    for ($i = 0; $i < 10; $i++) $kod .= $a[random_int(0, strlen($a) - 1)];
    $id = substr(strtr(base64_encode(random_bytes(18)), '+/', 'ab'), 0, 22);
    $p->beginTransaction();
    $p->prepare('UPDATE firma SET kod_h = ? WHERE id = ?')->execute([hash_hmac('sha256', 'firma|' . $kod, $cfg['sol']), $kto['firma_id']]);
    $p->prepare('INSERT INTO zapis (id, firma_id, kto_id, co, vec_id, detail, cas) VALUES (?,?,?,?,?,?,?)')->execute([$id, $kto['firma_id'], $kto['id'], 'kod_firmy_novy', $kto['firma_id'], json_encode(['ako' => 'jednorazová stránka']), time()]);
    $p->commit();
} catch (Throwable $e) {
    koniec('<p>Nepodarilo sa: chyba databázy. Nič sa nezmenilo.</p>');
}
@unlink($pocF);
koniec('<p>Firma: <b>' . htmlspecialchars($kto['nazov']) . '</b> · správca: ' . htmlspecialchars($kto['meno']) . '</p><p>Nový kód firmy:</p><div class="k">' . $kod . '</div><p>Zapíš si ho teraz, už sa neukáže. Nie je v ňom písmeno O ani I, ani číslica 0 ani 1.</p><p class="m">Táto stránka sa práve zmazala.</p>', true);
