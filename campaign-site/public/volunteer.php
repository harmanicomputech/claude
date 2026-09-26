<?php
/**
 * Volunteer sign-up handler for shared hosting (PHP 7.4+).
 *
 * - Honeypot + minimum fill time against bots.
 * - Rate limit per hashed IP (the raw IP is never stored).
 * - Saves to a CSV outside the web root when possible, with the consent record.
 * - Emails the campaign team if an address is configured.
 * - Never echoes submitted data back.
 *
 * Settings live in volunteer-config.php (see volunteer-config.sample.php).
 */

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function respond(bool $ok, string $message, int $status = 200): void
{
    global $wantsJson;
    http_response_code($status);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message]);
    } else {
        header('Location: ' . ($ok ? '/thank-you/' : '/sign-up-problem/'), true, 303);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(false, 'Method not allowed.', 405);
}

// ---- Config -------------------------------------------------------------
$config = [
    'team_email'       => '',                                  // where sign-ups are emailed; empty = no email
    'from_email'       => '',                                  // e.g. no-reply@yourdomain; empty = server default
    'data_dir'         => dirname(__DIR__) . '/volunteer-data', // outside public_html
    'ip_salt'          => '',                                  // random string; set in volunteer-config.php
    'max_per_ip_hour'  => 5,
    'max_total_hour'   => 400,
    'min_fill_seconds' => 3,
];
foreach ([dirname(__DIR__) . '/volunteer-config.php', __DIR__ . '/volunteer-config.php'] as $file) {
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
        }
        break;
    }
}

// Fall back to a protected folder inside the web root if the preferred folder can't be used.
$dataDir = $config['data_dir'];
if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true)) {
    $dataDir = __DIR__ . '/_data';
}
if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true)) {
    error_log('volunteer.php: cannot create data directory');
    respond(false, 'Sorry, sign-up is temporarily unavailable. Please try again later.', 500);
}
if (strpos(realpath($dataDir) ?: '', realpath(__DIR__) ?: "\0") === 0 && !is_file($dataDir . '/.htaccess')) {
    @file_put_contents($dataDir . '/.htaccess', "Require all denied\nDeny from all\n");
}

// ---- Spam checks ----------------------------------------------------------
if (trim((string)($_POST['website'] ?? '')) !== '') {
    respond(true, 'Thank you.'); // honeypot: pretend success
}
$started = (int)($_POST['started'] ?? 0);
if ($started > 0 && (microtime(true) * 1000 - $started) < $config['min_fill_seconds'] * 1000) {
    respond(true, 'Thank you.');
}

$salt = $config['ip_salt'] !== '' ? $config['ip_salt'] : hash('sha256', __FILE__ . php_uname());
$ipHash = substr(hash('sha256', $salt . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 24);

/** Checks the hourly limits; with $record, also counts this sign-up. Only saved sign-ups are counted. */
function rate_limited(string $dir, string $ipHash, int $perIp, int $total, bool $record = false): bool
{
    $file = $dir . '/ratelimit.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return false;
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fh), true) ?: [];
    $cutoff = time() - 3600;
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array)$times, fn ($t) => $t > $cutoff));
        if (!$data[$k]) {
            unset($data[$k]);
        }
    }
    $mine = count($data[$ipHash] ?? []);
    $all = array_sum(array_map('count', $data));
    $limited = $mine >= $perIp || $all >= $total;
    if ($record && !$limited) {
        $data[$ipHash][] = time();
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $limited;
}

if (rate_limited($dataDir, $ipHash, (int)$config['max_per_ip_hour'], (int)$config['max_total_hour'])) {
    respond(false, 'You have signed up several times already. Please wait an hour and try again.', 429);
}

// ---- Validation -------------------------------------------------------------
function clean(string $value, int $max): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, $max);
}

$lgas = ['Abakaliki', 'Afikpo North', 'Afikpo South (Edda)', 'Ebonyi', 'Ezza North', 'Ezza South', 'Ikwo',
    'Ishielu', 'Ivo', 'Izzi', 'Ohaozara', 'Ohaukwu', 'Onicha'];
$helpAllowed = ['Canvass in my ward', 'Serve as a polling unit agent', 'Share on WhatsApp and social media',
    'Mobilise women', 'Mobilise youth', 'Transport and logistics', 'Professional skills (legal, media, medical, IT)'];

$name    = clean((string)($_POST['name'] ?? ''), 100);
$phone   = preg_replace('/[\s\-().]/', '', (string)($_POST['phone'] ?? '')) ?? '';
$lga     = (string)($_POST['lga'] ?? '');
$ward    = clean((string)($_POST['ward'] ?? ''), 100);
$message = clean((string)($_POST['message'] ?? ''), 1000);
$help    = array_values(array_intersect($helpAllowed, array_map('strval', (array)($_POST['help'] ?? []))));
$consent = ($_POST['consent'] ?? '') === 'yes';

$errors = [];
if (mb_strlen($name) < 2) {
    $errors[] = 'name';
}
if (!preg_match('/^(?:\+?234|0)([789][01]\d{8})$/', $phone, $m)) {
    $errors[] = 'phone';
} else {
    $phone = '+234' . $m[1]; // normalise
}
if (!in_array($lga, $lgas, true)) {
    $errors[] = 'LGA';
}
if (!$consent) {
    respond(false, 'Please tick the consent box to continue.', 422);
}
if ($errors) {
    respond(false, 'Please check: ' . implode(', ', $errors) . '.', 422);
}

// ---- Store ------------------------------------------------------------------
$consentText = 'I agree that the campaign may store these details and contact me by phone, SMS or WhatsApp about volunteering and the campaign, as explained in the privacy notice. I can withdraw my consent at any time.';
$now = gmdate('Y-m-d\TH:i:s\Z');
$row = [$now, $name, $phone, $lga, $ward, implode('; ', $help), $message, 'yes', $now, 'privacy-notice-2026-09-26', $consentText, $ipHash];

// Stop spreadsheet formula injection when the CSV is opened in Excel.
// The phone (index 2) is already validated as +234 followed by digits, so it is left as is.
foreach ($row as $i => $v) {
    if ($i !== 2 && preg_match('/^[=+\-@\t\r]/', (string)$v)) {
        $row[$i] = "'" . $v;
    }
}

$csv = $dataDir . '/volunteers.csv';
$isNew = !is_file($csv);
$fh = @fopen($csv, 'a');
if (!$fh) {
    error_log('volunteer.php: cannot open CSV');
    respond(false, 'Sorry, sign-up is temporarily unavailable. Please try again later.', 500);
}
flock($fh, LOCK_EX);
if ($isNew) {
    fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly
    fputcsv($fh, ['submitted_at_utc', 'name', 'phone', 'lga', 'ward', 'help', 'message', 'consent', 'consent_at_utc', 'consent_version', 'consent_text', 'ip_hash'], ',', '"', '\\');
}
fputcsv($fh, $row, ',', '"', '\\');
flock($fh, LOCK_UN);
fclose($fh);
@chmod($csv, 0640);
rate_limited($dataDir, $ipHash, (int)$config['max_per_ip_hour'], (int)$config['max_total_hour'], true);

// ---- Email the team -------------------------------------------------------
$to = trim((string)$config['team_email']);
if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $subject = '=?UTF-8?B?' . base64_encode("New volunteer: {$lga}") . '?=';
    $body = "A new volunteer has signed up on the campaign website.\n\n"
        . "Name: {$name}\nPhone: {$phone}\nLGA: {$lga}\nWard: " . ($ward ?: '-') . "\n"
        . 'Can help with: ' . ($help ? implode(', ', $help) : '-') . "\n"
        . 'Message: ' . ($message ?: '-') . "\n\nConsent given: {$now} (UTC)\n\n"
        . "This person's details are covered by the Nigeria Data Protection Act 2023. Do not forward this email outside the campaign team.\n";
    $headers = ['Content-Type: text/plain; charset=UTF-8', 'MIME-Version: 1.0'];
    $from = trim((string)$config['from_email']);
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'From: Campaign website <' . $from . '>';
    }
    if (!@mail($to, $subject, $body, implode("\r\n", $headers))) {
        error_log('volunteer.php: mail() failed');
    }
}

respond(true, 'Thank you.');
