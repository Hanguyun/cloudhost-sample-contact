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
$receipt = null;
if ($method === 'GET') {
    $receiptId = $_SESSION['inquiry_receipt'] ?? null;
    unset($_SESSION['inquiry_receipt']);
    if (is_string($receiptId) && preg_match('/\A[1-9][0-9]{0,19}\z/', $receiptId) === 1) {
        $receipt = $receiptId;
    }
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

function saveInquiry(string $name, string $email, string $message): string
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

    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO cloudhost_sample_inquiries (name, email, message) VALUES (?, ?, ?)'
        );
        $statement->execute([$name, $email, $message]);
        $id = $db->lastInsertId();
        if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,19}\z/', $id) !== 1) {
            throw new RuntimeException('Missing inquiry ID.');
        }
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

$name = $email = $message = '';
$example = $method === 'GET' && $receipt === null && ($_GET['demo'] ?? null) === '1';
if ($example) {
    $name = 'CloudHost 테스트';
    $email = 'demo@example.com';
    $message = '샘플 앱에서 문의를 접수하고 DB 콘솔에서 저장된 행을 확인합니다.';
}
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
                $_SESSION['inquiry_receipt'] = saveInquiry($name, $email, $message);
                unset($_SESSION['form_token']);
                session_write_close();
                header('Location: /#receipt', true, 303);
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
    <title>문의 접수 | CloudHost 샘플 앱</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <header class="site-header">
        <div class="header-content">
            <a class="brand" href="/">
                <span class="brand-mark" aria-hidden="true">CH</span>
                <span>CloudHost<span class="brand-detail">샘플 앱</span></span>
            </a>
            <a class="header-link" href="#verification">시연 방법</a>
        </div>
    </header>
    <main class="layout">
        <div class="overview">
            <section class="intro" aria-labelledby="page-title">
                <h1 id="page-title">문의 접수</h1>
                <p>웹에서 문의를 남기고, 호스팅에 연결된 데이터베이스에 저장되는 과정까지 확인해 보세요.</p>
            </section>
            <section class="guide" id="verification" aria-labelledby="guide-title">
                <h2 id="guide-title">시연 방법</h2>
                <ol>
                    <li><strong>테스트 문의 입력</strong><span>직접 입력하거나 ‘예시로 채우기’를 누릅니다.</span></li>
                    <li><strong>접수 번호 확인</strong><span>저장이 끝나면 새 문의의 접수 번호가 표시됩니다.</span></li>
                    <li><strong>DB 콘솔에서 조회</strong><span>CloudHost 포털의 해당 서버 세트에서 DB 콘솔을 열고 <code>cloudhost_sample_inquiries</code> 테이블에서 같은 <code>id</code>를 찾습니다.</span></li>
                </ol>
            </section>
        </div>
        <form method="post" action="/#inquiry-form" class="inquiry" id="inquiry-form" aria-labelledby="form-title">
            <div class="form-heading">
                <h2 id="form-title">문의 작성</h2>
                <a class="example-link" href="/?demo=1#inquiry-form">예시로 채우기</a>
            </div>
            <?php if ($receipt !== null): ?>
                <div class="notice success" id="receipt" role="status">
                    <p>문의를 저장했습니다.</p>
                    <p>접수 번호 <strong class="receipt-id">#<?= escape($receipt) ?></strong></p>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <ul class="notice error" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <li><?= escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="form-note">시연용 데이터만 입력해 주세요. 입력한 내용은 호스팅 소유자가 DB 콘솔에서 조회할 수 있습니다.</p>
            <input type="hidden" name="token" value="<?= escape($token) ?>">
            <div class="fields-pair">
                <div class="field">
                    <label for="name">이름</label>
                    <input type="text" id="name" name="name" maxlength="60" autocomplete="off" placeholder="CloudHost 테스트" required value="<?= escape($name) ?>">
                </div>
                <div class="field">
                    <label for="email">이메일</label>
                    <input type="email" id="email" name="email" maxlength="254" autocomplete="off" placeholder="demo@example.com" required value="<?= escape($email) ?>">
                </div>
            </div>
            <div class="field">
                <label for="message">문의 내용</label>
                <textarea id="message" name="message" rows="6" maxlength="2000" aria-describedby="message-limit" placeholder="테스트용 문의 내용을 입력해 주세요." required><?= escape($message) ?></textarea>
                <span id="message-limit" class="field-hint">최대 2,000자</span>
            </div>
            <button class="submit" type="submit">문의 접수</button>
        </form>
    </main>
</body>
</html>
