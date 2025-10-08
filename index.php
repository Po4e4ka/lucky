<?php

require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// --------------------------------------
// Простой Basic Auth
// --------------------------------------
$basicUser = 'egor';
$basicPass = '12341234';
$telegramBotToken = '';
$telegramChatId = '';
$telegramReply = 1031;

$basicAuthMiddleware = function (Request $request, $handler) use ($basicUser, $basicPass) {
    $authHeader = $request->getHeaderLine('Authorization');

    if (!$authHeader || stripos($authHeader, 'Basic ') !== 0) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    $encoded = substr($authHeader, 6);
    $decoded = base64_decode($encoded);
    if (!$decoded) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    $parts = explode(':', $decoded, 2);
    if (count($parts) < 2) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    $userProvided = $parts[0];
    $passProvided = $parts[1];

    if ($userProvided !== $basicUser || $passProvided !== $basicPass) {
        $response = new \Slim\Psr7\Response(401);
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    return $handler->handle($request);
};

$conditionalAuthMiddleware = function (Request $request, $handler) use ($basicAuthMiddleware) {
    // Проверяем — Telegram ли это
    $userAgent = $request->getHeaderLine('User-Agent');
    $queryParams = $request->getQueryParams();

    $isTelegramWebApp =
        (isset($queryParams['tgWebAppData'])) ||
        (stripos($userAgent, 'Telegram') !== false);

    if ($isTelegramWebApp) {
        // Если открыт через Telegram — пропускаем без BasicAuth
        return $handler->handle($request);
    }

    // Иначе применяем обычную BasicAuth-проверку
    return $basicAuthMiddleware($request, $handler);
};

$app = AppFactory::create();

// --------------------------------------
// Функция отправки дампа в Telegram
// --------------------------------------
function sendDatabaseToTelegram(string $botToken, string $chatId, int $replyTo): array {
    $today = date('d-m-Y');
    $dumpPath = __DIR__ . "/dump_$today.sqlite";
    copy(__DIR__ . '/database.sqlite', $dumpPath);

    $postData = [
        'chat_id' => $chatId,
        'caption' => "📎 Дамп на $today",
        'document' => new CURLFile($dumpPath),
        'reply_to_message_id' => $replyTo
    ];

    $ch = curl_init("https://api.telegram.org/bot$botToken/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($result, true);

    return $data;
}

$app->get('/send-db', function (Request $request, Response $response) use ($telegramBotToken, $telegramChatId, $telegramReply) {
    $result = sendDatabaseToTelegram($telegramBotToken, $telegramChatId, $telegramReply);
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->add($conditionalAuthMiddleware);

// --------------------------------------
// Настройка PDO и миграция схемы
// --------------------------------------
$pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Создаём таблицу с полем last_lucky
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      lucky_count INTEGER NOT NULL DEFAULT 0,
      enabled INTEGER NOT NULL DEFAULT 1,
      last_lucky TEXT DEFAULT NULL
    );
");

// Для уже существующих баз добавляем колонку last_lucky, если её нет
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_lucky TEXT DEFAULT NULL;");
} catch (PDOException $e) {
    // если колонка уже есть — игнорируем
}

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
    $stmt = $pdo->query("
       SELECT id, name, lucky_count, enabled, last_lucky 
         FROM users 
        WHERE enabled=1 
        ORDER BY RANDOM() 
        LIMIT 1
    ");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $user = [
            'id' => null,
            'name' => 'Нет доступных пользователей',
            'lucky_count' => 0,
            'enabled' => 0,
            'last_lucky' => null
        ];
    }
    $response->getBody()->write(json_encode($user, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

// --------------------------------------
// Подтверждает выбор (инкрементирует счётчик и ставит дату)
// --------------------------------------
$app->post('/confirm-lucky', function (Request $request, Response $response) use ($pdo, $telegramChatId, $telegramBotToken, $telegramReply) {
    $data = json_decode($request->getBody()->getContents(), true);
    $userId = $data['id'] ?? null;

    if ($userId) {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            UPDATE users 
               SET lucky_count = lucky_count + 1,
                   last_lucky  = :last_lucky
             WHERE id = :id
        ");
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':last_lucky', $now, PDO::PARAM_STR);
        $stmt->execute();

        $respData = ['message' => 'Счастливчик подтверждён!', 'last_lucky' => $now];
    } else {
        $respData = ['message' => 'Не удалось подтвердить (id отсутствует)'];
    }

    try {
        sendDatabaseToTelegram($telegramBotToken, $telegramChatId, $telegramReply);
    } catch (\Throwable $e) {
        // игнорируем ошибки Telegram
    }

    $response->getBody()->write(json_encode($respData, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

// --------------------------------------
// Возвращает статистику в JSON (с last_lucky)
// --------------------------------------
$app->get('/stats', function (Request $request, Response $response) use ($pdo) {
    $stmt = $pdo->query("SELECT id, name, lucky_count, enabled, last_lucky FROM users");
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

// --------------------------------------
// Удалить пользователя (вызывается из браузера после подтверждения)
// --------------------------------------
$app->post('/settings/delete', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);
    $id   = $data['id'] ?? null;

    if ($id) {
        // Проверяем, что пользователь существует
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id");
        $stmtCheck->bindValue(':id', $id, PDO::PARAM_INT);
        $stmtCheck->execute();
        $exists = (bool) $stmtCheck->fetchColumn();

        if ($exists) {
            // Удаляем пользователя
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $respData = ['message' => 'Пользователь успешно удалён', 'id' => $id];
        } else {
            $respData = ['message' => 'Пользователь не найден', 'id' => $id];
        }
    } else {
        $respData = ['message' => 'Не указан id пользователя'];
    }

    $payload = json_encode($respData, JSON_UNESCAPED_UNICODE);
    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/send-webapp-btn', function ($request, $response) use ($telegramBotToken) {
    $queryParams = $request->getQueryParams();
    $chatId = $queryParams['chat_id'] ?? null;

    if (!$chatId) {
        $response->getBody()->write(json_encode(['error' => 'chat_id не указан']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    $keyboard = [
        'keyboard' => [
            [
                [
                    'text' => '🎲 Выбрать Счастливчика',
                    'web_app' => ['url' => 'https://lucky.devilops.fun']
                ]
            ]
        ],
        'resize_keyboard' => true
    ];

    $payload = [
        'chat_id' => $chatId,
        'text' => 'Запусти мини-приложение:',
        'reply_markup' => json_encode($keyboard)
    ];

    $ch = curl_init("https://api.telegram.org/bot$telegramBotToken/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $response->getBody()->write($result);
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
