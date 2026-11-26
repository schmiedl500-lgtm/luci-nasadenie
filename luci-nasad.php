<?php
// L.U.C.I – nahratie appky na hosting bez prihlasovania. © 2026 ETTA STEEL s. r. o. · autor a vlastník: Daniel Schmiedl
// Čo robí: stiahne zašifrovaný balík appky z JEDNÉHO pevne daného miesta (GitHub schmiedl500-lgtm/luci-nasadenie, vetva v0160),
// overí odtlačky SHA-256 podľa súboru aktualne.json, zálohuje terajšie súbory a vymení ich (najprv index.html, potom sw.js).
// Čo nevie: nič iné. Neprijíma žiadnu adresu, názov súboru ani obsah zvonka – zavolanie len zosúladí hosting s repozitárom.
// Použitie:  luci-nasad.php            → stav (čo je na hostingu, čo je v repozitári)
//            luci-nasad.php?a=nasad    → nahrať to, čo je v repozitári
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
const ZDROJ = 'https://raw.githubusercontent.com/schmiedl500-lgtm/luci-nasadenie/v0160/';
const SUBORY = ['index.html', 'sw.js'];            // poradie nahrávania
const MIN = ['index.html' => 200000, 'sw.js' => 500];
const MAX = 8000000;
$zdroj = getenv('LUCI_NASAD_ZDROJ') ?: ZDROJ;       // len pre miestnu skúšku (na hostingu premenná nie je)
$app = __DIR__ . '/m-7q4kx';
$data = dirname(__DIR__) . '/luci-data';
function von(array $o, int $kod = 200): void { http_response_code($kod); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit; }
function stiahni(string $url): ?string {
  if (function_exists('curl_init')) { $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'luci-nasad']);
    $d = curl_exec($c); $k = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c); return ($d !== false && $k === 200) ? (string)$d : null; }
  $d = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 40, 'follow_location' => 0, 'user_agent' => 'luci-nasad']])); return $d === false ? null : $d;
}
function odtlacky(string $app): array { $o = []; foreach (SUBORY as $s) { $p = "$app/$s"; $o[$s] = is_file($p) ? hash_file('sha256', $p) : null; } return $o; }
if (!is_dir($app) || !is_file("$app/index.html")) von(['ok' => false, 'chyba' => 'Priečinok appky som nenašiel.'], 500);
$mam = odtlacky($app);
$m = stiahni($zdroj . 'aktualne.json?t=' . time());
if ($m === null) von(['ok' => false, 'chyba' => 'Repozitár sa nedá prečítať (hosting nepustil sťahovanie alebo GitHub neodpovedá).', 'na_hostingu' => $mam], 502);
$j = json_decode($m, true);
$zle = !is_array($j) || !isset($j['verzia'], $j['priecinok'], $j['subory']) || !is_string($j['verzia']) || !is_string($j['priecinok']) || !preg_match('/^[0-9]{4}[a-z]?$/', $j['priecinok']) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $j['verzia']) || !is_array($j['subory']) || array_keys($j['subory']) !== SUBORY;
if (!$zle) foreach (SUBORY as $s) if (!is_string($j['subory'][$s]) || !preg_match('/^[0-9a-f]{64}$/', $j['subory'][$s])) $zle = true;
if ($zle) von(['ok' => false, 'chyba' => 'Súbor aktualne.json v repozitári nemá správny tvar – nič som nemenil.', 'na_hostingu' => $mam], 502);
$rovnake = true; foreach (SUBORY as $s) if ($mam[$s] !== $j['subory'][$s]) $rovnake = false;
$stav = ['v_repozitari' => ['verzia' => $j['verzia'], 'priecinok' => $j['priecinok'], 'subory' => $j['subory']], 'na_hostingu' => $mam, 'zhoda' => $rovnake];
if (($_GET['a'] ?? '') !== 'nasad') von(['ok' => true, 'akcia' => 'stav'] + $stav);
if ($rovnake) von(['ok' => true, 'akcia' => 'nasad', 'zmena' => false, 'sprava' => 'Na hostingu už je verzia ' . $j['verzia'] . ' – nič som nemenil.'] + $stav);
// najviac jedno nahratie za 20 sekúnd
$zamok = (is_dir($data) && is_writable($data) ? $data : sys_get_temp_dir()) . '/luci-nasad.zamok';
$f = @fopen($zamok, 'c+'); if (!$f || !flock($f, LOCK_EX | LOCK_NB)) von(['ok' => false, 'chyba' => 'Práve beží iné nahrávanie – skús o chvíľu.'], 429);
$pred = (int)trim((string)stream_get_contents($f)); if (time() - $pred < 20) von(['ok' => false, 'chyba' => 'Posledné nahrávanie bolo pred chvíľou – skús o 20 sekúnd.'], 429);
ftruncate($f, 0); rewind($f); fwrite($f, (string)time()); fflush($f);
// 1. stiahnuť všetko a overiť – až potom sa čokoľvek mení
$nove = [];
foreach (SUBORY as $s) {
  $d = stiahni($zdroj . $j['priecinok'] . '/' . $s . '?t=' . time());
  if ($d === null) von(['ok' => false, 'chyba' => "Súbor $s sa nepodarilo stiahnuť – nič som nemenil."] + $stav, 502);
  if (strlen($d) < MIN[$s] || strlen($d) > MAX) von(['ok' => false, 'chyba' => "Súbor $s má podozrivú veľkosť – nič som nemenil."] + $stav, 502);
  if (!hash_equals($j['subory'][$s], hash('sha256', $d))) von(['ok' => false, 'chyba' => "Odtlačok súboru $s nesedí s aktualne.json – nič som nemenil."] + $stav, 502);
  $nove[$s] = $d;
}
if (strpos($nove['index.html'], '<!') !== 0 || strpos($nove['index.html'], 'const S={sol:"') === false || strpos($nove['sw.js'], 'const VERZIA') === false) von(['ok' => false, 'chyba' => 'Balík nevyzerá ako zašifrovaná appka L.U.C.I – nič som nemenil.'] + $stav, 502);
// 2. záloha terajších súborov mimo webu
$zal = null; if (is_dir($data)) { $zd = "$data/zaloha"; if (!is_dir($zd)) @mkdir($zd, 0750, true);
  if (is_dir($zd) && is_writable($zd)) { $zal = 'appka_' . date('Y-m-d_His'); foreach (SUBORY as $s) if (is_file("$app/$s") && !@copy("$app/$s", "$zd/{$zal}_$s")) von(['ok' => false, 'chyba' => 'Zálohu sa nepodarilo zapísať – nič som nemenil.'] + $stav, 500); } }
// 3. výmena: zápis vedľa a premenovanie (telefón nikdy nedostane polovičný súbor)
foreach (SUBORY as $s) { $t = "$app/.$s.nove"; if (file_put_contents($t, $nove[$s], LOCK_EX) !== strlen($nove[$s]) || !rename($t, "$app/$s")) { @unlink($t); von(['ok' => false, 'chyba' => "Súbor $s sa nepodarilo vymeniť.", 'zaloha' => $zal, 'na_hostingu' => odtlacky($app)], 500); } }
if (function_exists('opcache_reset')) @opcache_reset();
$po = odtlacky($app); $sedi = true; foreach (SUBORY as $s) if ($po[$s] !== $j['subory'][$s]) $sedi = false;
von(['ok' => $sedi, 'akcia' => 'nasad', 'zmena' => true, 'verzia' => $j['verzia'], 'zaloha' => $zal, 'bolo' => $mam, 'na_hostingu' => $po, 'zhoda' => $sedi], $sedi ? 200 : 500);
