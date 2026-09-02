<?php
/*
 * Plugin Name: Плагин заявочной формы
 */

class ApplicationForms {
    const DB_HOST = 'localhost';
    const DB_USER = 'j84588200_result';
    const DB_PASS = '?fYt3K7yGaqv';
    const DB_NAME = 'j84588200_results';
    
    private static $db = null;
    
    // Подключение к базе данных (ленивая инициализация)
    private static function dbConnect() {
        if (self::$db === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            self::$db = new mysqli(self::DB_HOST, self::DB_USER, self::DB_PASS, self::DB_NAME);
            self::$db->set_charset('utf8');
        }
        return self::$db;
    }
    
    // Получение текущих настроек мероприятия
    private static function getCurrentSettings() {
        $db = self::dbConnect();
        $query = "SELECT app.event_id, event_name, app.season_id, season_name, event_date, pass
                  FROM appsettings app
                  JOIN Seasons s ON app.season_id = s.season_id
                  JOIN Events e ON app.event_id = e.event_id
                  LIMIT 1";
        $result = $db->query($query);
        return $result->fetch_assoc();
    }
    
    // Проверка, открыт ли прием заявок
    private static function isApplicationOpen($eventDate) {
        $deadline = new DateTime($eventDate);
        $deadline->modify('-17 hours');
        return new DateTime() < $deadline;
    }
    
    // Генерация HTML для сообщения о закрытии приема
    private static function renderClosedMessage($title) {
        ob_start();
        echo $title."<br>";
        ?>
        <center><H5>Прием заявок на сайте окончен</H5></center>
        <p style="font-size: 0.7rem; text-align: right; margin-right: 10%;">
            <a href="/заявки">Список заявок</a>
        </p>
        <?php
        return ob_get_clean();
    }
    
    // Генерация HTML для попапов
    private static function renderPopups() {
        ob_start();
        ?>
        <style>
            .bt-popup {
                width: 100%;
                border-radius: 8px;
                background-color: rgba(0,0,0,0.5);
                overflow: hidden;
                position: sticky;
                top: 200px;
                display: none;
                padding: 20px 0;
                margin: 10px 0;
            }
            
            .bt-popup-content {
                background-color: #c5c5c5;
                border-radius: 5px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                padding: 15px;
                max-width: 80%;
                margin: 0 auto;
            }
            
            .form-input {
                width: 80%;
                margin: 10px auto;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .radio-group {
                padding: 0 10%;
            }
            
            .radio-option {
                padding: 7px;
                border-radius: 8px;
                border: 1px solid #000;
                margin-bottom: 5px;
            }
            
            .submit-btn {
                padding: 10px 20px;
                background-color: #4CAF50;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 16px;
            }
            
            .submit-btn:hover {
                background-color: #45a049;
            }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const togglePopup = (id, show) => document.getElementById(id).style.display = show ? 'block' : 'none';
            const urlParams = new URLSearchParams(window.location.search);
            
            if (urlParams.get('r') === 'ok') togglePopup('popup1', true);
            if (urlParams.get('r') === 'err') togglePopup('popup2', true);
            
            window.showPopup = id => togglePopup(id, true);
            window.hidePopup = id => togglePopup(id, false);
        });
        </script>
        
        <div class="bt-popup" id="popup1">
            <div class="bt-popup-content">
                <h5 style="color: #333; margin: 0 0 15px 0;">Заявка принята!</h5>
                <button class="submit-btn" onclick="hidePopup('popup1')">Закрыть</button>
            </div>
        </div>

        <div class="bt-popup" id="popup2">
            <div class="bt-popup-content">
                <h5 style="color: #333; margin: 0 0 10px 0;">Возникли технические неполадки!</h5>
                <p style="color: #666; margin: 0 0 15px 0;">Попробуйте позже</p>
                <button class="submit-btn" onclick="hidePopup('popup2')">Закрыть</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // Шорткод для формы подачи заявки
    public static function renderApplicationForm() {
        $settings = self::getCurrentSettings();
        if (!$settings) {
            return '<div class="error">Не удалось загрузить настройки мероприятия</div>';
        }
        
        $titleApp = "<H5>Заявка ".htmlspecialchars($settings['event_name']).' '.htmlspecialchars($settings['season_name'])."</H5>";
        
        if (!self::isApplicationOpen($settings['event_date'])) {
            return self::renderClosedMessage($titleApp);
        }
        
        $classes = [
            'Полироль' => 'class1',
            'Стандарт' => 'class2',
            'Туризм' => 'class3',
            'Спорт' => 'class4'
        ];
        
        $formUrl = 'https://'.strstr(__DIR__, $_SERVER['SERVER_NAME']).'/save.php';
        
        ob_start();
        echo self::renderPopups();
        echo $titleApp;
        ?>
        <form action="<?php echo $formUrl; ?>" method="post" id="applicationForm">
            <h5 style="text-align: left; margin-left: 10%;">ФИО пилота</h5>
            <center><input type="text" class="form-input" maxlength="200" id="namePilot" name="namePilot" required></center>
            
            <h5 style="text-align: left; margin-left: 10%;">ФИО штурмана</h5>
            <center><input type="text" class="form-input" maxlength="200" id="nameShturman" name="nameShturman"></center>
            
            <h5 style="text-align: left; margin-left: 10%;">Ваш номер телефона для связи</h5>
            <center><input type="tel" class="form-input" maxlength="20" id="tel" name="tel" required></center>
            
            <h5 style="text-align: left; margin-left: 10%;">Класс</h5>
            <div class="radio-group">
                <?php foreach ($classes as $class => $id): ?>
                <div class="radio-option">
                    <input type="radio" id="<?php echo $id; ?>" name="class" value="<?php echo htmlspecialchars($class); ?>" required>
                    <label for="<?php echo $id; ?>"><?php echo htmlspecialchars($class); ?></label>
                </div>
                <?php endforeach; ?>
            </div>
            
            <h5 style="text-align: left; margin-left: 10%;">Автомобиль</h5>
            <center><input type="text" class="form-input" maxlength="200" id="car" name="car" required></center>
            
            <h5 style="text-align: left; margin-left: 10%;">Город</h5>
            <center><input type="text" class="form-input" maxlength="200" id="city" name="city" required></center>
            
            <input type="hidden" name="season_id" value="<?php echo (int)$settings['season_id']; ?>">
            <input type="hidden" name="event_id" value="<?php echo (int)$settings['event_id']; ?>">
            
            <center><button type="submit" class="submit-btn">Отправить</button></center>
            
            <p style="font-size: 0.7rem; text-align: right; margin-right: 10%;">
                <a href="/заявки">Список заявок</a>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }
    
    // Шорткод для отображения таблицы заявок
    public static function renderApplicationsTable() {
    
    $settings = self::getCurrentSettings();
        if (!$settings) {
            return '<div class="error">Не удалось загрузить настройки мероприятия</div>';
        }
    
    // Проверка пароля
    if (!isset($_POST['password'])) {
        return '
        <div style="max-width: 400px; margin: 50px auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
            <h3 style="text-align: center;">Доступ к таблице заявок</h3>
            <form method="POST">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Пароль:</label>
                    <input type="password" name="password" style="width: 100%; padding: 8px; box-sizing: border-box;">
                </div>
                <button type="submit" style="width: 100%; padding: 10px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">Войти</button>
            </form>
        </div>';
    }
    
    // Правильный пароль (замените на свой)
    $correct_password = htmlspecialchars($settings['pass']);
    
    if ($_POST['password'] !== $correct_password) {
        return '<div class="error" style="text-align: center; color: red; margin: 20px;">Неверный пароль</div>
        <div style="text-align: right; margin: 20px;"><a href="../заявка/">Назад</a></div>';
    }
        try {

            $db = self::dbConnect();
            $query = "SELECT class, namePilot, nameShturman, city, car, tel, 
                     DATE_FORMAT(time, '%d.%m.%Y %H:%i') as formatted_time 
                     FROM appparticipation 
                     WHERE season_id = ? AND event_id = ? 
                     ORDER BY time DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $settings['season_id'], $settings['event_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            
            $excelUrl = 'https://'.strstr(__DIR__, $_SERVER['SERVER_NAME']);
            
            ob_start();
            ?>
            <div style="margin: 20px 0;">
                <form action="<?php echo $excelUrl; ?>/excel.php" method="POST">
                    <input type="image" src="<?php echo $excelUrl; ?>/ms-excel.png" width="25" height="25" 
                           style="align: right; margin:5px" alt="submit" title="Скачать таблицу">
                </form>
            </div>
            
            <div style="overflow-x: auto;">
                <table class="applications-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f2f2f2;">
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">№</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Дата</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Класс</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Пилот</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Штурман</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Город</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Авто</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Телефон</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $row): ?>
                        <tr style="border-bottom: 1px solid #ddd; <?= $i % 2 === 0 ? 'background-color: #fff;' : 'background-color: #f9f9f9;' ?>">
                            <td style="padding: 12px;"><?= $i + 1 ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['formatted_time']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['class']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['namePilot']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['nameShturman']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['city']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['car']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['tel']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            return ob_get_clean();
            
        } catch (Exception $e) {
            error_log('Ошибка при загрузке заявок: ' . $e->getMessage());
            return '<div class="error">Произошла ошибка при загрузке данных. Пожалуйста, попробуйте позже.</div>';
        }
    }
}

// Регистрация шорткодов
add_shortcode('appforms-301', ['ApplicationForms', 'renderApplicationForm']);
add_shortcode('appforms-302', ['ApplicationForms', 'renderApplicationsTable']);

// Регистрируем пользовательские параметры запроса
add_filter('query_vars', function($vars) {
    $vars[] = 'season_id';
    $vars[] = 'event_id';
    return $vars;
});