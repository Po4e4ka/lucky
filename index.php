<?php

require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// --------------------------------------
// Пример простой Basic Auth
// --------------------------------------

// Указываем пару логин:пароль
$basicUser = 'egor';
$basicPass = '12341234';

// Создадим middleware для проверки Basic Auth
$basicAuthMiddleware = function (Request $request, $handler) use ($basicUser, $basicPass) {
    // Извлекаем заголовок Authorization
    $authHeader = $request->getHeaderLine('Authorization');

    // Если нет заголовка или он не начинается с "Basic ", просим авторизоваться
    if (!$authHeader || stripos($authHeader, 'Basic ') !== 0) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    // Берём base64 часть (после Basic )
    $encoded = substr($authHeader, 6);
    $decoded = base64_decode($encoded);
    if (!$decoded) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    // Ожидаем формат "user:pass"
    $parts = explode(':', $decoded, 2);
    if (count($parts) < 2) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    $userProvided = $parts[0];
    $passProvided = $parts[1];

    // Сверяем с нашими кредами
    if ($userProvided !== $basicUser || $passProvided !== $basicPass) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    // Если всё окей, пропускаем дальше
    return $handler->handle($request);
};

// --------------------------------------

$app = AppFactory::create();

$app->add($basicAuthMiddleware);

// Настраиваем подключение к SQLite
$pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// На всякий случай добавим столбец "enabled" (если его нет)
// В реальном проекте лучше выполнять миграции или проверять через PRAGMA.
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN enabled INTEGER NOT NULL DEFAULT 1");
} catch (Exception $e) {
    // Если столбец уже есть — ничего не делаем.
}

// На всякий случай создаём таблицу, если не существует (на тот случай, если БД чистая)
$pdo->exec("\n    CREATE TABLE IF NOT EXISTS users (\n        id INTEGER PRIMARY KEY AUTOINCREMENT,\n        name TEXT NOT NULL,\n        lucky_count INTEGER NOT NULL DEFAULT 0,\n        enabled INTEGER NOT NULL DEFAULT 1\n    );\n");

// --------------------------------------
// Главная страница
// --------------------------------------
$app->get('/', function (Request $request, Response $response) {
    $html = file_get_contents('main.html');
    $response->getBody()->write($html);
    return $response;
});

// --------------------------------------
// Возвращает случайного пользователя (только тех, у кого enabled=1)
// --------------------------------------
$app->get('/pick-lucky', function (Request $request, Response $response) use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM users WHERE enabled=1 ORDER BY RANDOM() LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $user = ['id' => null, 'name' => 'Нет доступных пользователей', 'lucky_count' => 0];
    }

    $response->getBody()->write(json_encode($user, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

// --------------------------------------
// Подтверждает выбор (инкрементирует счётчик)
// --------------------------------------
$app->post('/confirm-lucky', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);
    $userId = $data['id'] ?? null;

    if ($userId) {
        $stmt = $pdo->prepare("UPDATE users SET lucky_count = lucky_count + 1 WHERE id = :id");
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $respData = [ 'message' => 'Счастливчик подтверждён!' ];
    } else {
        $respData = [ 'message' => 'Не удалось подтвердить (id отсутствует)' ];
    }

    $response->getBody()->write(json_encode($respData, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

// --------------------------------------
// Возвращает статистику в JSON
// --------------------------------------
$app->get('/stats', function (Request $request, Response $response) use ($pdo) {
    $stmt = $pdo->query("SELECT id, name, lucky_count, enabled FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode($users, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

// --------------------------------------
// Страница настроек
// --------------------------------------
$app->get('/settings', function (Request $request, Response $response) use ($pdo) {
    $html = file_get_contents('settings.html');
    $response->getBody()->write($html);
    return $response;
});

// --------------------------------------
// Добавить пользователя
// --------------------------------------
$app->post('/settings/add', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);
    $name = $data['name'] ?? null;
    if ($name) {
        $stmt = $pdo->prepare("INSERT INTO users (name, lucky_count, enabled) VALUES (:name, 0, 1)");
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
    }
    return $response;
});

// --------------------------------------
// Увеличить счётчик на 1
// --------------------------------------
$app->post('/settings/increment', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);
    $id = $data['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare("UPDATE users SET lucky_count = lucky_count + 1 WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }
    return $response;
});

// --------------------------------------
// Редактировать пользователя (имя, счётчик и enabled)
// --------------------------------------
$app->post('/settings/edit', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);
    $id = $data['id'] ?? null;

    // Возможно, пользователь редактирует только enabled, а может и имя/lucky_count
    // Поэтому учитываем, что некоторые поля могут отсутствовать
    if (!$id) {
        return $response;
    }

    // Получим текущего пользователя
    $stmtSelect = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmtSelect->bindValue(':id', $id, PDO::PARAM_INT);
    $stmtSelect->execute();
    $user = $stmtSelect->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return $response;
    }

    // Обновляем поля, если они присутствуют в запросе
    $newName = array_key_exists('name', $data) ? $data['name'] : $user['name'];
    $newCount = array_key_exists('lucky_count', $data) ? $data['lucky_count'] : $user['lucky_count'];
    $newEnabled = array_key_exists('enabled', $data) ? $data['enabled'] : $user['enabled'];

    $stmtUpdate = $pdo->prepare("UPDATE users SET name = :name, lucky_count = :count, enabled = :enabled WHERE id = :id");
    $stmtUpdate->bindValue(':id', $id, PDO::PARAM_INT);
    $stmtUpdate->bindValue(':name', $newName, PDO::PARAM_STR);
    $stmtUpdate->bindValue(':count', $newCount, PDO::PARAM_INT);
    $stmtUpdate->bindValue(':enabled', $newEnabled, PDO::PARAM_INT);
    $stmtUpdate->execute();

    return $response;
});

$app->run();
