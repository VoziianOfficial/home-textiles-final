<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    header('Allow: GET, POST'); respond(405, ['success'=>false, 'message'=>'Method not allowed.']);
}
$configPath = __DIR__ . '/config/config.js';
if (!is_file($configPath)) respond(503, ['success'=>false, 'message'=>'The contact service is not configured.']);
try {
    $configSource = file_get_contents($configPath);
    if (!is_string($configSource) || !preg_match('/\A\s*window\.SITE_CONFIG\s*=\s*(\{.*\})\s*;\s*\z/s', $configSource, $matches)) throw new RuntimeException('Invalid configuration');
    $config = json_decode($matches[1], true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($config)) throw new RuntimeException('Invalid configuration');
}
catch (Throwable $e) { respond(503, ['success'=>false, 'message'=>'The contact service is not configured.']); }
$settings = $config['form'] ?? [];
if (empty($settings['enabled'])) respond(503, ['success'=>false, 'message'=>'The contact form is temporarily unavailable.']);
ini_set('session.use_strict_mode', '1');
session_name('ht_contact');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly'=>true, 'samesite'=>'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') !== 'token') respond(400, ['success'=>false, 'message'=>'Invalid request.']);
    respond(200, ['csrf'=>$_SESSION['csrf']]);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) respond(413, ['success'=>false, 'message'=>'Your message is too long.']);
$raw = file_get_contents('php://input', false, null, 0, 20001);
if (strlen($raw) > 20000) respond(413, ['success'=>false, 'message'=>'Your message is too long.']);
try { $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
catch (Throwable $e) { respond(400, ['success'=>false, 'message'=>'Invalid request.']); }
if (!is_array($input)) respond(400, ['success'=>false, 'message'=>'Invalid request.']);
foreach (['name','email','subject','message','website','csrf','consent'] as $field) {
    if (isset($input[$field]) && !is_string($input[$field])) respond(422, ['success'=>false, 'message'=>'Invalid form values.']);
}
if (!hash_equals($_SESSION['csrf'], (string)($input['csrf'] ?? ''))) respond(403, ['success'=>false, 'message'=>'Your form session expired. Please try again.']);
if (!empty($input['website'])) respond(422, ['success'=>false, 'message'=>'The request could not be accepted.']);
$name = trim($input['name'] ?? ''); $email = trim($input['email'] ?? '');
$subject = trim($input['subject'] ?? ''); $message = trim($input['message'] ?? '');
if (strlen($name)<1 || strlen($name)>300 || preg_match('/[\r\n\x00]/', $name) || strlen($email)>254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email) || strlen($message)<10 || strlen($message)>((int)($settings['maxLength'] ?? 5000))*4 || !in_array($subject, $settings['subjects'] ?? [], true) || ($input['consent'] ?? '') !== 'on') {
    respond(422, ['success'=>false, 'message'=>'Please enter a valid name, email and message, and confirm the Privacy Policy.']);
}
$rateDir = getenv('CONTACT_RATE_DIR') ?: sys_get_temp_dir() . '/ht-contact-rate';
if (!is_dir($rateDir) && !@mkdir($rateDir, 0700, true) && !is_dir($rateDir)) respond(503, ['success'=>false, 'message'=>'The contact service is temporarily unavailable.']);
$key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . __DIR__);
$handle = @fopen($rateDir . '/' . $key . '.json', 'c+');
if (!$handle || !flock($handle, LOCK_EX)) respond(503, ['success'=>false, 'message'=>'Please try again later.']);
$history = json_decode(stream_get_contents($handle), true);
$history = array_values(array_filter(is_array($history)?$history:[], fn($t)=>is_int($t) && $t>time()-3600));
if (count($history)>=(int)($settings['rateLimitPerHour'] ?? 8)) {flock($handle, LOCK_UN); fclose($handle); respond(429, ['success'=>false, 'message'=>'Too many requests. Please try again later.']);}
$history[] = time(); ftruncate($handle,0); rewind($handle); fwrite($handle,json_encode($history)); fflush($handle); flock($handle,LOCK_UN); fclose($handle);
$recipient = trim($config['email'] ?? ''); $sender = trim($settings['senderEmail'] ?? '');
foreach ([$recipient,$sender] as $address) {
    if (!filter_var($address,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$address) || str_ends_with(strtolower($address),'.example')) respond(503, ['success'=>false,'message'=>'Email delivery is not connected yet. Your request has not been sent.']);
}
$prefix = preg_replace('/[\r\n]/',' ', $settings['subjectPrefix'] ?? 'Website enquiry');
$title = $prefix . ': ' . $subject;
$body = "Name: {$name}\nEmail: {$email}\nTopic: {$subject}\n\n{$message}\n\nPrivacy acknowledgement: yes\nReceived (UTC): " . gmdate('c');
$accepted = false;
if (($settings['transport'] ?? 'mail') === 'resend') {
    $apiKey = getenv('RESEND_API_KEY');
    if (!$apiKey || !function_exists('curl_init')) respond(503, ['success'=>false,'message'=>'Email delivery is not configured. Your request has not been sent.']);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['from'=>$sender,'to'=>[$recipient],'reply_to'=>$email,'subject'=>$title,'text'=>$body])]);
    $response=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $result=is_string($response)?json_decode($response,true):null;
    $accepted=$code>=200 && $code<300 && !empty($result['id']);
} else {
    $headers=['From'=>$sender,'Reply-To'=>$email,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8'];
    $encodedTitle='=?UTF-8?B?'.base64_encode($title).'?=';
    $accepted=@mail($recipient,$encodedTitle,$body,$headers);
}
if (!$accepted) respond(502,['success'=>false,'message'=>'The mail server did not accept your request. Please try again later.']);
$_SESSION['csrf']=bin2hex(random_bytes(32));
respond(200,['success'=>true,'message'=>$settings['successMessage'] ?? 'Thank you! Your request has been received.']);
