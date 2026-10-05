<?php
$envPath = __DIR__ . '/../.env';
$config = [
    'app_name' => 'CleanManage',
    'site_url' => 'http://localhost:8000',
    'smtp' => [
        'enabled' => false,
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'your_email@gmail.com',
        'password' => 'your_app_password_here',
        'from_email' => 'no-reply@cleanmanage.local',
        'from_name' => 'CleanManage'
    ],
    'whatsapp' => [
        'enabled' => false,
        'api_token' => '',
        'phone_number_id' => '',
        'api_url' => 'https://graph.facebook.com/v18.0/{phone_number_id}/messages'
    ]
];

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = trim($value, "\"'");
        }
        $values[$key] = $value;
    }

    return $values;
}

$envValues = loadEnvFile($envPath);
$configEnvironmentKeys = [
    'APP_URL',
    'SMTP_ENABLED', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME',
    'WHATSAPP_ENABLED', 'WHATSAPP_API_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_API_URL'
];
foreach ($configEnvironmentKeys as $environmentKey) {
    $processValue = getenv($environmentKey);
    if ($processValue !== false) {
        $envValues[$environmentKey] = (string)$processValue;
    }
}

foreach ($envValues as $key => $value) {
    $key = strtoupper($key);
    if ($key === 'APP_URL') {
        $config['site_url'] = $value;
    } elseif ($key === 'SMTP_ENABLED') {
        $config['smtp']['enabled'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    } elseif ($key === 'SMTP_HOST') {
        $config['smtp']['host'] = $value;
    } elseif ($key === 'SMTP_PORT') {
        $config['smtp']['port'] = (int)$value;
    } elseif ($key === 'SMTP_ENCRYPTION') {
        $config['smtp']['encryption'] = $value;
    } elseif ($key === 'SMTP_USERNAME') {
        $config['smtp']['username'] = $value;
    } elseif ($key === 'SMTP_PASSWORD') {
        $config['smtp']['password'] = $value;
    } elseif ($key === 'SMTP_FROM_EMAIL') {
        $config['smtp']['from_email'] = $value;
    } elseif ($key === 'SMTP_FROM_NAME') {
        $config['smtp']['from_name'] = $value;
    } elseif ($key === 'WHATSAPP_ENABLED') {
        $config['whatsapp']['enabled'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    } elseif ($key === 'WHATSAPP_API_TOKEN') {
        $config['whatsapp']['api_token'] = $value;
    } elseif ($key === 'WHATSAPP_PHONE_NUMBER_ID') {
        $config['whatsapp']['phone_number_id'] = $value;
    } elseif ($key === 'WHATSAPP_API_URL') {
        $config['whatsapp']['api_url'] = $value;
    }
}

function getSmtpConfig(): array
{
    global $config;
    return $config['smtp'] ?? [];
}

function getWhatsAppConfig(): array
{
    global $config;
    return $config['whatsapp'] ?? [];
}

function smtpSendEmail(string $to, string $subject, string $body, string $fromEmail = null, string $fromName = 'CleanManage'): bool
{
    $smtp = getSmtpConfig();
    if (empty($smtp['enabled']) || empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password'])) {
        return false;
    }

    $host = $smtp['host'];
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower((string)($smtp['encryption'] ?? 'tls'));
    $username = $smtp['username'];
    $password = $smtp['password'];
    $from = $fromEmail ?: ($smtp['from_email'] ?? 'no-reply@cleanmanage.local');

    $socket = @fsockopen($host, $port, $errno, $errstr, 20);
    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, 20);
    $reply = fgets($socket, 512);
    if (strpos($reply, '220') !== 0) {
        fclose($socket);
        return false;
    }

    $commands = [
        'EHLO ' . $host,
        ($encryption === 'ssl' ? 'STARTTLS' : 'STARTTLS'),
        'AUTH LOGIN',
        base64_encode($username),
        base64_encode($password),
        'MAIL FROM:<' . $from . '>',
        'RCPT TO:<' . $to . '>',
        'DATA',
        "From: \"" . $fromName . "\" <" . $from . ">\r\nTo: <" . $to . ">\r\nSubject: " . $subject . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $body . "\r\n.",
        'QUIT'
    ];

    foreach ($commands as $command) {
        if ($command === 'STARTTLS') {
            fwrite($socket, "STARTTLS\r\n");
            $response = fgets($socket, 512);
            if (strpos($response, '220') !== 0) {
                fclose($socket);
                return false;
            }
            $tls = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($tls === false) {
                fclose($socket);
                return false;
            }
            continue;
        }

        fwrite($socket, $command . "\r\n");
        $response = fgets($socket, 512);
        if (in_array($command, ['MAIL FROM:<'.$from.'>', 'RCPT TO:<' . $to . '>', 'DATA'], true) && strpos($response, '250') !== 0 && strpos($response, '251') !== 0 && strpos($response, '354') !== 0) {
            fclose($socket);
            return false;
        }

        if ($command === 'QUIT') {
            break;
        }
    }

    fclose($socket);
    return true;
}

function sendWhatsAppBookingNotification(string $phone, string $customerName, string $serviceType, string $scheduledDate, string $status): bool
{
    $whatsapp = getWhatsAppConfig();
    if (empty($whatsapp['enabled']) || empty($whatsapp['api_token']) || empty($whatsapp['phone_number_id'])) {
        return false;
    }

    $cleanPhone = normalizeWhatsAppNumber($phone);
    if ($cleanPhone === '') {
        return false;
    }

    $message = buildBookingConfirmationMessage($customerName, $serviceType, $scheduledDate, $status);
    $apiUrl = str_replace('{phone_number_id}', rawurlencode($whatsapp['phone_number_id']), $whatsapp['api_url'] ?? 'https://graph.facebook.com/v18.0/{phone_number_id}/messages');

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $cleanPhone,
        'type' => 'text',
        'text' => ['body' => $message]
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer " . $whatsapp['api_token'] . "\r\nContent-Type: application/json\r\nAccept: application/json",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 20,
        ]
    ]);

    $response = @file_get_contents($apiUrl, false, $context);
    if ($response === false || $response === '') {
        return false;
    }

    $data = json_decode($response, true);
    if (is_array($data) && isset($data['error'])) {
        return false;
    }

    return true;
}
