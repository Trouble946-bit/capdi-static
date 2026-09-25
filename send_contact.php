<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html');
    exit;
}

function redirect_with_status(string $status): void
{
    header('Location: contact.html?status=' . urlencode($status));
    exit;
}

function log_mail_error(string $step, string $detail = ''): void
{
    $base = '[CAPDI Contact SMTP] step=' . $step;
    if ($detail !== '') {
        $base .= ' detail=' . $detail;
    }
    error_log($base);

    $line = date('Y-m-d H:i:s') . ' ' . $base . PHP_EOL;
    @file_put_contents(__DIR__ . '/contact-mail.log', $line, FILE_APPEND);
}

function smtp_read_response($socket): string
{
    $response = '';

    while (($line = fgets($socket, 512)) !== false) {
        $response .= $line;
        // SMTP multiline responses continue while char 4 is '-'
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtp_expect($socket, array $expectedCodes, string &$lastResponse = ''): bool
{
    $lastResponse = smtp_read_response($socket);
    if ($lastResponse === '') {
        return false;
    }

    $code = (int) substr($lastResponse, 0, 3);
    return in_array($code, $expectedCodes, true);
}

function smtp_write($socket, string $command): bool
{
    return fwrite($socket, $command . "\r\n") !== false;
}

$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    redirect_with_status('error');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('error');
}

$smtpHost = getenv('CAPDI_SMTP_HOST') ?: 'glacier.mxrouting.net';
$smtpPort = (int) (getenv('CAPDI_SMTP_PORT') ?: '465');
$smtpUsername = trim((string) (getenv('CAPDI_SMTP_USERNAME') ?: ''));
$smtpPassword = (string) (getenv('CAPDI_SMTP_PASSWORD') ?: '');
$fromEmail = trim((string) (getenv('CAPDI_SMTP_FROM') ?: $smtpUsername));
$toEmail = trim((string) (getenv('CAPDI_CONTACT_TO') ?: 'info@capdi.org'));

if ($smtpUsername === '' || $smtpPassword === '' || $fromEmail === '' || $toEmail === '') {
    log_mail_error('config', 'missing required SMTP environment variables');
    redirect_with_status('error');
}

$subject = 'Contact from ' . $name . ' - CAPDI';
$bodyLines = [
    'Contact form submission',
    '-----------------------',
    'Name: ' . $name,
    'Email: ' . $email,
    'Phone: ' . ($phone !== '' ? $phone : 'N/A'),
    '',
    'Message:',
    $message,
];

$body = implode("\r\n", $bodyLines);

$headers = [
    'Date: ' . date(DATE_RFC2822),
    'From: CAPDI Contact <' . $fromEmail . '>',
    'To: ' . $toEmail,
    'Reply-To: ' . $email,
    'Subject: ' . $subject,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$dataMessage = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n";

// Dot-stuffing for SMTP DATA section
$dataMessage = preg_replace('/(^|\r\n)\./', '$1..', $dataMessage) ?? $dataMessage;

$context = stream_context_create([
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$socket = @stream_socket_client(
    'ssl://' . $smtpHost . ':' . $smtpPort,
    $errno,
    $errstr,
    15,
    STREAM_CLIENT_CONNECT,
    $context
);

if ($socket === false) {
    log_mail_error('connect', 'errno=' . $errno . ' err=' . $errstr);
    redirect_with_status('error');
}

stream_set_timeout($socket, 15);

$lastResponse = '';
$ok = true;

if (!smtp_expect($socket, [220], $lastResponse)) {
    $ok = false;
    log_mail_error('greeting', trim($lastResponse));
}

if ($ok && !smtp_write($socket, 'EHLO capdimw.org')) {
    $ok = false;
    log_mail_error('ehlo-write');
}
if ($ok && !smtp_expect($socket, [250], $lastResponse)) {
    $ok = false;
    log_mail_error('ehlo-read', trim($lastResponse));
}

if ($ok && !smtp_write($socket, 'AUTH LOGIN')) {
    $ok = false;
    log_mail_error('auth-login-write');
}
if ($ok && !smtp_expect($socket, [334], $lastResponse)) {
    $ok = false;
    log_mail_error('auth-login-read', trim($lastResponse));
}

if ($ok && !smtp_write($socket, base64_encode($smtpUsername))) {
    $ok = false;
    log_mail_error('auth-user-write');
}
if ($ok && !smtp_expect($socket, [334], $lastResponse)) {
    $ok = false;
    log_mail_error('auth-user-read', trim($lastResponse));
}

if ($ok && !smtp_write($socket, base64_encode($smtpPassword))) {
    $ok = false;
    log_mail_error('auth-pass-write');
}
if ($ok && !smtp_expect($socket, [235], $lastResponse)) {
    $ok = false;
    log_mail_error('auth-pass-read', trim($lastResponse));
}

if ($ok && !smtp_write($socket, 'MAIL FROM:<' . $fromEmail . '>')) {
    $ok = false;
    log_mail_error('mail-from-write');
}
if ($ok && !smtp_expect($socket, [250], $lastResponse)) {
    $ok = false;
    log_mail_error('mail-from-read', trim($lastResponse));
}

if ($ok && !smtp_write($socket, 'RCPT TO:<' . $toEmail . '>')) {
    $ok = false;
    log_mail_error('rcpt-to-write');
}
if ($ok && !smtp_expect($socket, [250, 251], $lastResponse)) {
    $ok = false;
    log_mail_error('rcpt-to-read', trim($lastResponse));
}

if ($ok && !smtp_write($socket, 'DATA')) {
    $ok = false;
    log_mail_error('data-write');
}
if ($ok && !smtp_expect($socket, [354], $lastResponse)) {
    $ok = false;
    log_mail_error('data-read', trim($lastResponse));
}

if ($ok && !smtp_write($socket, $dataMessage . "\r\n.")) {
    $ok = false;
    log_mail_error('message-write');
}
if ($ok && !smtp_expect($socket, [250], $lastResponse)) {
    $ok = false;
    log_mail_error('message-read', trim($lastResponse));
}

if (!smtp_write($socket, 'QUIT')) {
    log_mail_error('quit-write');
} elseif (!smtp_expect($socket, [221], $lastResponse)) {
    log_mail_error('quit-read', trim($lastResponse));
}

fclose($socket);

if (!$ok) {
    redirect_with_status('error');
}

redirect_with_status('success');
