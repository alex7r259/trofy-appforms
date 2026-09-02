<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Конфигурация
const DB_CONFIG = [
    'host' => 'localhost',
    'user' => 'j84588200_result',
    'pass' => '?fYt3K7yGaqv',
    'name' => 'j84588200_results'
];
const REDIRECT_URL = 'https://parmarace.ru/wp-admin/admin.php?page=app';

// Функция для безопасного получения POST-данных
function getSanitizedPostData(array $fields): array {
    $data = [];
    foreach ($fields as $field) {
        $data[$field] = isset($_POST[$field]) ? htmlspecialchars(trim($_POST[$field])) : '';
    }
    return $data;
}

// Основной обработчик
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . REDIRECT_URL);
    exit;
}

try {
    // Проверка обязательных полей
    $requiredFields = ['season_id', 'event_id', 'pass'];
    $formData = getSanitizedPostData($requiredFields);
    
    foreach ($requiredFields as $field) {
        if (empty($formData[$field])) {
            throw new RuntimeException("Не заполнено обязательное поле: $field");
        }
    }

    // Подключение к БД
    $db = mysqli_connect(
        DB_CONFIG['host'],
        DB_CONFIG['user'],
        DB_CONFIG['pass'],
        DB_CONFIG['name']
    );
    
    if (!$db) {
        throw new RuntimeException('Ошибка подключения к базе данных: ' . mysqli_connect_error());
    }

    // Подготовка и выполнение запроса
    $sql = 'UPDATE `appsettings` 
            SET season_id = ?, event_id = ?, pass = ?
            WHERE id = 1';
    
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса: ' . mysqli_error($db));
    }

    // Привязка параметров (все как строки, так как htmlspecialchars возвращает строку)
    mysqli_stmt_bind_param($stmt, 'sss', $formData['season_id'], $formData['event_id'], $formData['pass']);

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Ошибка выполнения запроса: ' . mysqli_stmt_error($stmt));
    }

    // Проверка, что запрос действительно что-то обновил
    if (mysqli_affected_rows($db) === 0) {
        throw new RuntimeException('Ни одна запись не была обновлена');
    }

    header('Location: ' . REDIRECT_URL . '&success=1');
    exit;

} catch (Throwable $e) {
    error_log('Ошибка: ' . $e->getMessage());
    header('Location: ' . REDIRECT_URL . '&error=1');
    exit;
} finally {
    if (isset($stmt)) mysqli_stmt_close($stmt);
    if (isset($db)) mysqli_close($db);
}