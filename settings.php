<?php
// Добавляем пункт меню в админку
add_action('admin_menu', 'app_settings');

function app_settings() {
    add_menu_page(
        'Настройки',
        'Заявки',
        'manage_options',
        'app',
        'app_callback',
        'dashicons-editor-table',
        20
    );
    
    add_action('admin_enqueue_scripts', 'app_enqueue_scripts');
}

// Подключаем скрипты
function app_enqueue_scripts($hook) {
    if ($hook != 'toplevel_page_app') {
        return;
    }
    
    wp_enqueue_script(
        'app-ajax-script', 
        plugins_url('/js/app-ajax.js', __FILE__), 
        array('jquery')
    );
    
    wp_localize_script('app-ajax-script', 'app_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('result_settings_actions_nonce')
    ));
}

// Основная функция вывода страницы
function app_callback() {
    try {
        $link = mysqli_connect("localhost", "j84588200_result", "?fYt3K7yGaqv", "j84588200_results");
        
        // Получаем текущие настройки
        $settings = mysqli_fetch_assoc(
            mysqli_query($link, "SELECT * FROM appsettings LIMIT 1")
        );
        
        // Получаем все сезоны
        $seasons = mysqli_fetch_all(
            mysqli_query($link, "SELECT * FROM seasons"),
            MYSQLI_ASSOC
        );
        
        // Получаем события для текущего сезона
        $events = array();
        if (!empty($settings['season_id'])) {
            $events = mysqli_fetch_all(
                mysqli_query($link, 
                    "SELECT * FROM events WHERE season_id = " . $settings['season_id']
                ),
                MYSQLI_ASSOC
            );
        }
        
        // Формируем HTML
        echo '<div class="wrap"><h1>Настройки заявок</h1>';
        echo '<form id="app-settings-form">';
        
        // Поле выбора сезона
        echo '<p><label for="season_id">Сезон:</label><br>';
        echo '<select id="season_id" name="season_id">';
        foreach ($seasons as $season) {
            $selected = ($season['season_id'] == $settings['season_id']) ? 'selected' : '';
            echo '<option value="'.$season['season_id'].'" '.$selected.'>'.$season['season_name'].'</option>';
        }
        echo '</select></p>';
        
        // Поле выбора события
        echo '<p><label for="event_id">Этап:</label><br>';
        echo '<select id="event_id" name="event_id">';
        foreach ($events as $event) {
            $selected = ($event['event_id'] == $settings['event_id']) ? 'selected' : '';
            echo '<option value="'.$event['event_id'].'" '.$selected.'>'.$event['event_name'].'</option>';
        }
        echo '</select></p>';
        
        // Поле пароля
        echo '<p><label for="pass">Пароль:</label><br>';
        echo '<input type="text" id="pass" name="pass" value="'.esc_attr($settings['pass']).'"></p>';
        
        echo '<p><button type="submit" class="button button-primary">Сохранить</button></p>';
        echo '</form>';
        echo '<div id="app-message"></div>';
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="error"><p>Ошибка подключения к базе данных: '.$e->getMessage().'</p></div>';
    }
}
?>