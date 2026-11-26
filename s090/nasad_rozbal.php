<?php
// L.U.C.I – jednorazové nasadenie servera zo zašifrovaného balíka. © 2026 ETTA STEEL s. r. o.
// Bez správneho kľúča (posiela sa pri volaní, nie je uložený nikde) nespraví nič. Po úspechu zmaže balík aj seba. Po 24 h sa zmaže tiež.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function von(array $x, bool $zmaz = false): void { if ($zmaz) { @unlink(__DIR__ . '/balik.enc'); @unlink(__FILE__); } echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
if (time() - (int)@filemtime(__FILE__) > 86400) von(['ok' => false, 'chyba' => 'vyprsane'], true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') von(['ok' => false, 'chyba' => 'post']);
$V = json_decode((string)file_get_contents('php://input'), true);
$kluc = is_array($V) && is_string($V['kluc'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $V['kluc']) ? hex2bin($V['kluc']) : null;
$verzia = is_array($V) && is_string($V['verzia'] ?? null) ? $V['verzia'] : '';
$b = @file_get_contents(__DIR__ . '/balik.enc');
if ($kluc === null || $b === false || strlen($b) < 100) von(['ok' => false, 'chyba' => 'vstup']);
$kod = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $kluc, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
if ($kod === false) von(['ok' => false, 'chyba' => 'kluc']);
if (strncmp($kod, '<?php', 5) !== 0 || $verzia === '' || strpos($kod, "const VERZIA_SERVERA = '" . $verzia . "'") === false) von(['ok' => false, 'chyba' => 'obsah']);
$stary = (string)@file_get_contents(__DIR__ . '/index.php');
$sv = preg_match("/const VERZIA_SERVERA = '([0-9.]+)'/", $stary, $m) ? $m[1] : 'neznama';
$data = dirname(__DIR__, 3) . '/luci-data';
if (!is_dir($data)) $data = __DIR__ . '/data';
$zal = $data . '/zaloha';
$zalohy = [];
if (is_file($data . '/luci.sqlite')) {
    if (!is_dir($zal) && !@mkdir($zal, 0700, true)) von(['ok' => false, 'chyba' => 'zaloha_priecinok']);
    try { $p = new PDO('sqlite:' . $data . '/luci.sqlite'); $p->exec('PRAGMA busy_timeout=5000'); $p->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $p = null; } catch (Throwable $e) { von(['ok' => false, 'chyba' => 'databaza']); }
    $c = $zal . '/luci_' . gmdate('Y-m-d_Hi') . '_pred_' . str_replace('.', '', $verzia) . '.sqlite';
    if (!@copy($data . '/luci.sqlite', $c) || filesize($c) !== filesize($data . '/luci.sqlite')) von(['ok' => false, 'chyba' => 'zaloha_db']);
    $zalohy[] = basename($c) . ' (' . filesize($c) . ' B)';
    if ($stary !== '') { $s = $zal . '/api_index_' . $sv . '.php'; if (@file_put_contents($s, $stary) === strlen($stary)) $zalohy[] = basename($s); }
}
$n = __DIR__ . '/index.php.novy';
if (@file_put_contents($n, $kod) !== strlen($kod) || !@rename($n, __DIR__ . '/index.php')) { @unlink($n); von(['ok' => false, 'chyba' => 'zapis']); }
if (function_exists('opcache_invalidate')) @opcache_invalidate(__DIR__ . '/index.php', true);
von(['ok' => true, 'stara' => $sv, 'nova' => $verzia, 'bajtov' => strlen($kod), 'zalohy' => $zalohy], true);
