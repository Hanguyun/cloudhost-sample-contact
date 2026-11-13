<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}
if ($method === 'HEAD') {
    exit;
}

session_name('__Host-CloudHostContact');
session_set_cookie_params([
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_cache_limiter('');
if (!session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1])) {
    http_response_code(503);
    exit('문의 양식을 준비하지 못했습니다. 잠시 후 다시 시도해 주세요.');
}
$_SESSION['form_token'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['form_token'];
$saved = $method === 'GET' && ($_SESSION['inquiry_saved'] ?? false) === true;
if ($saved) {
    unset($_SESSION['inquiry_saved']);
}

function postValue(string $key, int $maxBytes): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) && strlen($value) <= $maxBytes ? trim($value) : '';
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function saveInquiry(string $name, string $email, string $message): void
{
    $config = json_decode(file_get_contents('/etc/cloudhost/database.json'), true, 8, JSON_THROW_ON_ERROR);
    $db = new PDO(
        'mysql:host=' . $config['db_ip'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_TIMEOUT => 10,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_SSL_CA => '/etc/cloudhost/database-ca.pem',
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ],
    );

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cloudhost_sample_inquiries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            email VARCHAR(254) NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $statement = $db->prepare(
        'INSERT INTO cloudhost_sample_inquiries (name, email, message) VALUES (?, ?, ?)'
    );
    $statement->execute([$name, $email, $message]);
}

$name = $email = $message = '';
$errors = [];
if ($method === 'POST') {
    if (!hash_equals($token, postValue('token', 64))) {
        http_response_code(403);
        $errors[] = '양식이 만료되었습니다. 다시 작성해 주세요.';
    } else {
        $name = postValue('name', 240);
        $email = postValue('email', 254);
        $message = postValue('message', 8000);
        if (preg_match('/\A[^\p{Cc}]{1,60}\z/u', $name) !== 1) {
            $errors[] = '이름을 1~60자로 입력해 주세요.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '이메일 주소를 확인해 주세요.';
        }
        if (preg_match('/\A.{1,2000}\z/su', $message) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) === 1) {
            $errors[] = '문의 내용을 1~2,000자로 입력해 주세요.';
        }
        if ($errors) {
            http_response_code(422);
        } else {
            try {
                saveInquiry($name, $email, $message);
                unset($_SESSION['form_token']);
                $_SESSION['inquiry_saved'] = true;
                session_write_close();
                header('Location: /', true, 303);
                exit;
            } catch (Throwable $error) {
                error_log('CloudHost sample contact form could not save an inquiry.');
                http_response_code(503);
                $errors[] = '문의를 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
            }
        }
    }
}
session_write_close();
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>문의 남기기 | CloudHost 샘플 앱</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<main>
    <section aria-labelledby="page-title" class="intro">
        <p class="product">CloudHost 샘플 앱</p>
        <h1 id="page-title">문의 남기기</h1>
        <p>접수한 문의는 이 앱의 데이터베이스에 저장됩니다.</p>
        <p class="hint">시연용 양식입니다. 테스트 이름과 이메일을 사용해 주세요.</p>
    </section>
    <form method="post" action="/" aria-labelledby="form-title">
        <h2 id="form-title">문의 정보</h2>
        <?php if ($saved): ?>
            <p class="notice success" role="status">문의를 저장했습니다.</p>
        <?php endif; ?>
        <?php if ($errors): ?>
            <ul class="notice error" role="alert">
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <input type="hidden" name="token" value="<?= escape($token) ?>">
        <label for="name">이름</label>
        <input type="text" id="name" name="name" maxlength="60" autocomplete="off" required value="<?= escape($name) ?>">
        <label for="email">이메일</label>
        <input type="email" id="email" name="email" maxlength="254" autocomplete="off" placeholder="demo@example.com" required value="<?= escape($email) ?>">
        <label for="message">문의 내용</label>
        <textarea id="message" name="message" rows="5" maxlength="2000" required><?= escape($message) ?></textarea>
        <button type="submit">문의 접수</button>
    </form>
</main>
</body>
</html>
