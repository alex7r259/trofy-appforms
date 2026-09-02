<?php

// Включаем строгую типизацию и отчет об ошибках
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Отключаем вывод ошибок на экран в продакшене
ini_set('log_errors', '1');      // Включаем логирование ошибок

// Конфигурация базы данных
const DB_HOST = 'localhost';
const DB_USER = 'j84588200_result';
const DB_PASS = '?fYt3K7yGaqv';
const DB_NAME = 'j84588200_results';

// Обрабатываем отправку формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Проверяем обязательные поля
        $requiredFields = ['namePilot', 'tel', 'class', 'car', 'city', 'season_id', 'event_id'];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                throw new RuntimeException("Не заполнено обязательное поле: $field");
            }
        }

        // Очищаем и проверяем входные данные
        $formData = [
            'namePilot' => htmlspecialchars(trim($_POST['namePilot'])),
            'nameShturman' => isset($_POST['nameShturman']) ? htmlspecialchars(trim($_POST['nameShturman'])) : null,
            'tel' => htmlspecialchars(trim($_POST['tel'])),
            'class' => htmlspecialchars(trim($_POST['class'])),
            'car' => htmlspecialchars(trim($_POST['car'])),
            'city' => htmlspecialchars(trim($_POST['city'])),
            'season_id' => (int)$_POST['season_id'],
            'event_id' => (int)$_POST['event_id'],
            'time' => date('Y-m-d H:i:s')
        ];

        // Подключаемся к базе данных
        $db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$db) {
            throw new RuntimeException('Ошибка подключения к базе данных: ' . mysqli_connect_error());
        }

        // Подготавливаем SQL-запрос с параметрами
        $sql = 'INSERT INTO appparticipation 
                (time, namePilot, nameShturman, tel, class, car, city, season_id, event_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $stmt = mysqli_prepare($db, $sql);
        if (!$stmt) {
            throw new RuntimeException('Ошибка подготовки запроса: ' . mysqli_error($db));
        }

        // Привязываем параметры
        mysqli_stmt_bind_param(
            $stmt,
            'sssssssii',  // Типы параметров: s-строка, i-целое число
            $formData['time'],
            $formData['namePilot'],
            $formData['nameShturman'],
            $formData['tel'],
            $formData['class'],
            $formData['car'],
            $formData['city'],
            $formData['season_id'],
            $formData['event_id']
        );

        // Выполняем запрос
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Ошибка выполнения запроса: ' . mysqli_stmt_error($stmt));
        }
        
$to      = 'mail@parmarace.ru';
$subject = 'Новая заявка';
$message = "Заявка " . $formData['time'] . "\r\nФИО Пилота: " . $formData['namePilot'] . "\r\nФИО Штурмана: " . $formData['nameShturman'] . "\r\nТел.: " . $formData['tel'] . "\r\nКласс: " . $formData['class'] . "\r\nАвто: " . $formData['car'] . "\r\nГород: " . $formData['city'];
$headers = array(
    'From' => 'forms@parmarace.ru',
    'X-Mailer' => 'PHP/' . phpversion()
);

mail($to, $subject, $message, $headers);

        // Успешное выполнение - редирект
        header('Location: https://parmarace.ru/заявка?r=ok');
        exit;

    } catch (Throwable $e) {
        // Логируем ошибку
        error_log('Ошибка в форме заявки: ' . $e->getMessage());
        
        // Редирект с ошибкой
        header('Location: https://parmarace.ru/заявка?r=err');
        exit;
    } finally {
        // Освобождаем ресурсы
        if (isset($stmt)) mysqli_stmt_close($stmt);
        if (isset($db)) mysqli_close($db);
    }
    
    
} else {
    // Если запрос не POST - перенаправляем
    header('Location: заявка');
    exit;
}
?>