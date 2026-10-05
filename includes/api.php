<?php

function apiJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(string $message, int $status = 400, array $extra = []): void
{
    $payload = ['success' => false, 'message' => $message];
    foreach ($extra as $key => $value) {
        $payload[$key] = $value;
    }

    apiJson($payload, $status);
}

function extractBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
        return null;
    }

    return trim($matches[1]);
}

function getApiUser(mysqli $conn): ?array
{
    $token = extractBearerToken();
    if ($token === null || $token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT u.id, u.name, u.email, u.role FROM api_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return null;
    }

    $touch = $conn->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?');
    if ($touch) {
        $touch->bind_param('s', $tokenHash);
        $touch->execute();
        $touch->close();
    }

    return $user;
}

function requireApiAuth(mysqli $conn): array
{
    $user = getApiUser($conn);
    if ($user === null) {
        apiError('Unauthorized. Please log in again.', 401);
    }

    return $user;
}

function createApiToken(mysqli $conn, int $userId): string
{
    $token = 'cm_' . bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30));

    $stmt = $conn->prepare('INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Token creation failed: ' . $conn->error);
    }

    $stmt->bind_param('iss', $userId, $hash, $expires);
    $stmt->execute();
    $stmt->close();

    return $token;
}
