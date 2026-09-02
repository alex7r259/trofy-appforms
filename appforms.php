<?php
/**
 * Plugin Name: Trofy AppForms
 * Description: Форма регистрации экипажей, защищённый список заявок и экспорт Excel.
 * Version: 2.0.1
 * Author: Alex7r259
 */

defined('ABSPATH') || exit;

final class Trofy_AppForms {
    private const VERSION = '2.0.1';
    private const ACCESS_TTL = 28800;
    private const ACCESS_COOKIE = 'trofy_appforms_access';
    private static $db = null;

    public static function init(): void {
        add_shortcode('appforms-301', [__CLASS__, 'render_application_form']);
        add_shortcode('event_registration', [__CLASS__, 'render_application_form']);
        add_shortcode('appforms-302', [__CLASS__, 'render_applications']);
        add_filter('query_vars', static function ($vars) { $vars[] = 'season_id'; $vars[] = 'event_id'; return $vars; });
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_assets']);
        add_action('wp_ajax_trofy_load_events', [__CLASS__, 'ajax_load_events']);
        add_action('wp_ajax_trofy_save_settings', [__CLASS__, 'ajax_save_settings']);
        add_action('wp_ajax_trofy_delete_application', [__CLASS__, 'ajax_delete_application']);
        add_action('admin_post_nopriv_trofy_submit_application', [__CLASS__, 'submit_application']);
        add_action('admin_post_trofy_submit_application', [__CLASS__, 'submit_application']);
        add_action('admin_post_trofy_public_login', [__CLASS__, 'public_login']);
        add_action('admin_post_nopriv_trofy_public_login', [__CLASS__, 'public_login']);
        add_action('admin_post_trofy_public_logout', [__CLASS__, 'public_logout']);
        add_action('admin_post_nopriv_trofy_public_logout', [__CLASS__, 'public_logout']);
        add_action('admin_post_trofy_export', [__CLASS__, 'export_excel']);
        add_action('admin_post_nopriv_trofy_export', [__CLASS__, 'export_excel']);
    }

    private static function db() {
        if (self::$db instanceof mysqli) return self::$db;
        foreach (['TROFY_APPFORMS_DB_HOST','TROFY_APPFORMS_DB_USER','TROFY_APPFORMS_DB_PASS','TROFY_APPFORMS_DB_NAME'] as $constant) {
            if (!defined($constant)) throw new RuntimeException('Не настроено подключение к БД заявок: ' . $constant);
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$db = new mysqli(TROFY_APPFORMS_DB_HOST, TROFY_APPFORMS_DB_USER, TROFY_APPFORMS_DB_PASS, TROFY_APPFORMS_DB_NAME);
        self::$db->set_charset('utf8mb4');
        return self::$db;
    }

    /**
     * Возвращает настройки события из URL, если season_id/event_id переданы.
     * Это сохраняет привязку формы к конкретной странице события.
     * Если параметры не переданы, используется событие из appsettings.
     */
    private static function settings(): ?array {
        $season_id = absint(get_query_var('season_id'));
        $event_id = absint(get_query_var('event_id'));

        if ($season_id && $event_id) {
            $stmt = self::db()->prepare("SELECT e.event_id, e.season_id, app.pass, e.event_name, s.season_name, e.event_date FROM Events e JOIN Seasons s ON e.season_id=s.season_id LEFT JOIN appsettings app ON app.id=1 WHERE e.event_id=? AND e.season_id=? LIMIT 1");
            $stmt->bind_param('ii', $event_id, $season_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                if (empty($row['pass'])) {
                    $fallback = self::db()->query("SELECT pass FROM appsettings WHERE id=1 LIMIT 1")->fetch_assoc();
                    $row['pass'] = $fallback['pass'] ?? '';
                }
                return $row;
            }
        }

        $row = self::db()->query("SELECT app.event_id, app.season_id, app.pass, e.event_name, s.season_name, e.event_date FROM appsettings app JOIN Seasons s ON app.season_id=s.season_id JOIN Events e ON app.event_id=e.event_id ORDER BY app.id ASC LIMIT 1")->fetch_assoc();
        return $row ?: null;
    }

    private static function redirect(string $url, array $args = []): void { wp_safe_redirect(add_query_arg($args, $url)); exit; }

    private static function current_url(): string {
        $url = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        return esc_url_raw(remove_query_arg(['r','login'], $url));
    }

    private static function applications_url(?array $settings = null): string {
        $url = home_url('/заявки/');
        $season_id = $settings['season_id'] ?? absint(get_query_var('season_id'));
        $event_id = $settings['event_id'] ?? absint(get_query_var('event_id'));
        if ($season_id && $event_id) $url = add_query_arg(['season_id'=>$season_id,'event_id'=>$event_id], $url);
        return $url;
    }

    private static function has_public_access(): bool {
        if (empty($_COOKIE[self::ACCESS_COOKIE])) return false;
        $token = sanitize_text_field(wp_unslash($_COOKIE[self::ACCESS_COOKIE]));
        return (bool)get_transient('trofy_appforms_access_' . hash('sha256', $token));
    }

    private static function issue_public_access(): void {
        $token = bin2hex(random_bytes(32));
        set_transient('trofy_appforms_access_' . hash('sha256', $token), true, self::ACCESS_TTL);
        setcookie(self::ACCESS_COOKIE, $token, ['expires'=>time()+self::ACCESS_TTL,'path'=>COOKIEPATH ?: '/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
    }

    private static function revoke_public_access(): void {
        if (!empty($_COOKIE[self::ACCESS_COOKIE])) { $token=sanitize_text_field(wp_unslash($_COOKIE[self::ACCESS_COOKIE])); delete_transient('trofy_appforms_access_' . hash('sha256',$token)); }
        setcookie(self::ACCESS_COOKIE, '', ['expires'=>time()-3600,'path'=>COOKIEPATH ?: '/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
    }

    public static function frontend_assets(): void {
        wp_register_style('trofy-appforms', false, [], self::VERSION); wp_enqueue_style('trofy-appforms'); wp_add_inline_style('trofy-appforms', self::frontend_css());
    }

    public static function admin_assets(string $hook): void {
        if ($hook !== 'toplevel_page_app') return;
        wp_enqueue_style('dashicons'); wp_register_style('trofy-appforms-admin', false, [], self::VERSION); wp_enqueue_style('trofy-appforms-admin'); wp_add_inline_style('trofy-appforms-admin', self::admin_css());
        wp_enqueue_script('jquery'); wp_add_inline_script('jquery','window.TrofyAppForms='.wp_json_encode(['ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('trofy_appforms_admin')]).';','before'); wp_add_inline_script('jquery',self::admin_js(),'after');
    }

    public static function admin_menu(): void {
        add_menu_page('Заявки','Заявки','manage_options','app',[__CLASS__,'admin_page'],'dashicons-clipboard',20);
    }

    public static function admin_page(): void {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        try {
            $settings=self::settings();
            $seasons=self::db()->query('SELECT season_id,season_name FROM Seasons ORDER BY season_id DESC')->fetch_all(MYSQLI_ASSOC);
            $events=[];
            if ($settings) { $stmt=self::db()->prepare('SELECT event_id,event_name,event_date FROM Events WHERE season_id=? ORDER BY event_date,event_id'); $stmt->bind_param('i',$settings['season_id']); $stmt->execute(); $events=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); }
            $rows=$settings?self::get_applications((int)$settings['season_id'],(int)$settings['event_id']):[];
        } catch(Throwable $e) { echo '<div class="wrap"><div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div></div>'; return; }
        ?>
        <div class="wrap trofy-admin">
            <div class="trofy-admin-head"><div><span class="trofy-eyebrow">TROFY APPFORMS</span><h1>Заявки</h1><p>Управление регистрацией экипажей</p></div><?php if($settings): ?><a class="button button-primary trofy-export" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=trofy_export&scope=admin'),'trofy_export')); ?>"><span class="dashicons dashicons-media-spreadsheet"></span> Скачать Excel</a><?php endif; ?></div>
            <div class="trofy-settings-card"><div class="trofy-field"><label for="trofy-season">Сезон</label><select id="trofy-season"><option value="">Выберите сезон</option><?php foreach($seasons as $season): ?><option value="<?php echo (int)$season['season_id']; ?>" <?php selected($settings['season_id']??0,$season['season_id']); ?>><?php echo esc_html($season['season_name']); ?></option><?php endforeach; ?></select></div><div class="trofy-field"><label for="trofy-event">Этап</label><select id="trofy-event"><?php foreach($events as $event): ?><option value="<?php echo (int)$event['event_id']; ?>" <?php selected($settings['event_id']??0,$event['event_id']); ?>><?php echo esc_html($event['event_name']); ?></option><?php endforeach; ?></select></div><div class="trofy-field trofy-password"><label for="trofy-pass">Пароль для списка</label><input type="password" id="trofy-pass" value="" placeholder="Оставьте пустым, чтобы не менять"><small>Хранится только в виде хеша.</small></div><button type="button" class="button button-primary trofy-save" id="trofy-save">Сохранить</button></div>
            <?php if($settings): ?><div class="trofy-stats"><div><strong><?php echo count($rows); ?></strong><span>всего заявок</span></div><?php foreach(['Полироль','Стандарт','Туризм','Спорт'] as $class): $count=count(array_filter($rows,static fn($r)=>$r['class']===$class)); ?><div><strong><?php echo $count; ?></strong><span><?php echo esc_html($class); ?></span></div><?php endforeach; ?></div><div class="trofy-toolbar"><input type="search" id="trofy-search" placeholder="Поиск: пилот, штурман, автомобиль, город..."><select id="trofy-class"><option value="">Все классы</option><option>Полироль</option><option>Стандарт</option><option>Туризм</option><option>Спорт</option></select></div><div class="trofy-table-wrap"><table class="trofy-table"><thead><tr><th>#</th><th>Дата</th><th>Класс</th><th>Пилот</th><th>Штурман</th><th>Город</th><th>Автомобиль</th><th>Телефон</th><th></th></tr></thead><tbody id="trofy-rows"><?php self::render_rows($rows); ?></tbody></table></div><?php endif; ?><div id="trofy-admin-message"></div>
        </div><?php
    }

    private static function get_applications(int $season_id,int $event_id): array { $stmt=self::db()->prepare("SELECT id,class,namePilot,nameShturman,city,car,tel,DATE_FORMAT(time,'%d.%m.%Y %H:%i') formatted_time FROM appparticipation WHERE season_id=? AND event_id=? ORDER BY time DESC"); $stmt->bind_param('ii',$season_id,$event_id); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC); }

    private static function render_rows(array $rows): void {
        if(!$rows){echo '<tr><td colspan="9" class="trofy-empty">Заявок пока нет</td></tr>';return;}
        foreach($rows as $i=>$row){$search=strtolower(implode(' ',[$row['class'],$row['namePilot'],$row['nameShturman'],$row['city'],$row['car'],$row['tel']]));echo '<tr data-class="'.esc_attr($row['class']).'" data-search="'.esc_attr($search).'"><td data-label="#">'.($i+1).'</td><td data-label="Дата">'.esc_html($row['formatted_time']).'</td><td data-label="Класс"><span class="trofy-class">'.esc_html($row['class']).'</span></td><td data-label="Пилот"><strong>'.esc_html($row['namePilot']).'</strong></td><td data-label="Штурман">'.esc_html($row['nameShturman']).'</td><td data-label="Город">'.esc_html($row['city']).'</td><td data-label="Автомобиль">'.esc_html($row['car']).'</td><td data-label="Телефон"><a href="tel:'.esc_attr($row['tel']).'">'.esc_html($row['tel']).'</a></td><td><button type="button" class="button-link-delete trofy-delete" data-id="'.(int)$row['id'].'" title="Удалить"><span class="dashicons dashicons-trash"></span></button></td></tr>';}
    }

    public static function ajax_load_events(): void { check_ajax_referer('trofy_appforms_admin','nonce'); if(!current_user_can('manage_options'))wp_send_json_error('Недостаточно прав.',403);$season_id=absint($_POST['season_id']??0);$stmt=self::db()->prepare('SELECT event_id,event_name FROM Events WHERE season_id=? ORDER BY event_date,event_id');$stmt->bind_param('i',$season_id);$stmt->execute();wp_send_json_success($stmt->get_result()->fetch_all(MYSQLI_ASSOC)); }

    public static function ajax_save_settings(): void { check_ajax_referer('trofy_appforms_admin','nonce');if(!current_user_can('manage_options'))wp_send_json_error('Недостаточно прав.',403);try{$season_id=absint($_POST['season_id']??0);$event_id=absint($_POST['event_id']??0);$pass=isset($_POST['pass'])?trim(wp_unslash($_POST['pass'])):'';if(!$season_id||!$event_id)wp_send_json_error('Выберите сезон и этап.');$check=self::db()->prepare('SELECT event_id FROM Events WHERE event_id=? AND season_id=? LIMIT 1');$check->bind_param('ii',$event_id,$season_id);$check->execute();if(!$check->get_result()->fetch_assoc())wp_send_json_error('Выбранный этап не относится к выбранному сезону.');if($pass!==''){$hash=wp_hash_password($pass);$stmt=self::db()->prepare('UPDATE appsettings SET season_id=?,event_id=?,pass=? WHERE id=1');$stmt->bind_param('iis',$season_id,$event_id,$hash);}else{$stmt=self::db()->prepare('UPDATE appsettings SET season_id=?,event_id=? WHERE id=1');$stmt->bind_param('ii',$season_id,$event_id);}$stmt->execute();wp_send_json_success('Настройки сохранены.');}catch(Throwable $e){wp_send_json_error($e->getMessage());} }

    public static function ajax_delete_application(): void { check_ajax_referer('trofy_appforms_admin','nonce');if(!current_user_can('manage_options'))wp_send_json_error('Недостаточно прав.',403);$id=absint($_POST['id']??0);if(!$id)wp_send_json_error('Некорректная заявка.');$stmt=self::db()->prepare('DELETE FROM appparticipation WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();wp_send_json_success(); }

    public static function render_application_form(): string {
        try{$settings=self::settings();}catch(Throwable $e){return '<div class="trofy-alert">Ошибка подключения к заявкам.</div>';}
        if(!$settings)return '<div class="trofy-alert">Не удалось загрузить настройки мероприятия.</div>';$deadline=new DateTime($settings['event_date']);$deadline->modify('-17 hours');if(new DateTime()>=$deadline)return '<div class="trofy-closed"><h3>Приём заявок окончен</h3><p><a href="'.esc_url(self::applications_url($settings)).'">Список заявок</a></p></div>';
        $redirect_to=self::current_url();
        ob_start();?><div class="trofy-form"><div class="trofy-form-head"><span>РЕГИСТРАЦИЯ</span><h2><?php echo esc_html($settings['event_name']); ?></h2><p><?php echo esc_html($settings['season_name']); ?></p></div><form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="trofy_submit_application"><?php wp_nonce_field('trofy_submit_application','_wpnonce'); ?><input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>"><input type="text" name="website" class="trofy-hp" tabindex="-1" autocomplete="off"><?php self::form_input('namePilot','ФИО пилота',true);self::form_input('nameShturman','ФИО штурмана');self::form_input('tel','Телефон',true,'tel');self::form_input('car','Автомобиль',true);self::form_input('city','Город',true); ?><div class="trofy-input"><label>Класс *</label><div class="trofy-radios"><?php foreach(['Полироль','Стандарт','Туризм','Спорт'] as $class): ?><label><input type="radio" name="class" value="<?php echo esc_attr($class); ?>" required><span><?php echo esc_html($class); ?></span></label><?php endforeach; ?></div></div><input type="hidden" name="season_id" value="<?php echo (int)$settings['season_id']; ?>"><input type="hidden" name="event_id" value="<?php echo (int)$settings['event_id']; ?>"><button class="trofy-submit" type="submit">Отправить заявку <span>→</span></button></form></div><?php return ob_get_clean();
    }

    private static function form_input(string $name,string $label,bool $required=false,string $type='text'):void{echo '<div class="trofy-input"><label for="trofy-'.esc_attr($name).'">'.esc_html($label).($required?' *':'').'</label><input id="trofy-'.esc_attr($name).'" type="'.esc_attr($type).'" name="'.esc_attr($name).'" maxlength="200"'.($required?' required':'').'></div>';}

    public static function submit_application(): void {
        $redirect=!empty($_POST['redirect_to'])?esc_url_raw(wp_unslash($_POST['redirect_to'])):home_url('/заявка/');
        if(!wp_validate_redirect($redirect,home_url('/заявка/'))) $redirect=home_url('/заявка/');
        if(!isset($_POST['_wpnonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])),'trofy_submit_application'))self::redirect($redirect,['r'=>'err']);if(!empty($_POST['website']))self::redirect($redirect,['r'=>'ok']);
        try{$fields=['namePilot','nameShturman','tel','class','car','city'];$data=[];foreach($fields as $field)$data[$field]=isset($_POST[$field])?sanitize_text_field(wp_unslash($_POST[$field])):'';$season_id=absint($_POST['season_id']??0);$event_id=absint($_POST['event_id']??0);if(!$data['namePilot']||!$data['tel']||!$data['class']||!$data['car']||!$data['city']||!$season_id||!$event_id)throw new RuntimeException('Не заполнены обязательные поля.');$check=self::db()->prepare('SELECT event_id,event_date FROM Events WHERE event_id=? AND season_id=? LIMIT 1');$check->bind_param('ii',$event_id,$season_id);$check->execute();$event=$check->get_result()->fetch_assoc();if(!$event)throw new RuntimeException('Некорректное мероприятие.');$deadline=new DateTime($event['event_date']);$deadline->modify('-17 hours');if(new DateTime()>=$deadline)throw new RuntimeException('Приём заявок окончен.');$allowed=['Полироль','Стандарт','Туризм','Спорт'];if(!in_array($data['class'],$allowed,true))throw new RuntimeException('Некорректный класс.');$stmt=self::db()->prepare('INSERT INTO appparticipation (time,namePilot,nameShturman,tel,class,car,city,season_id,event_id) VALUES (NOW(),?,?,?,?,?,?,?,?)');$stmt->bind_param('ssssssii',$data['namePilot'],$data['nameShturman'],$data['tel'],$data['class'],$data['car'],$data['city'],$season_id,$event_id);$stmt->execute();$message="Новая заявка\nПилот: {$data['namePilot']}\nШтурман: {$data['nameShturman']}\nТел.: {$data['tel']}\nКласс: {$data['class']}\nАвто: {$data['car']}\nГород: {$data['city']}";wp_mail(get_option('admin_email'),'Новая заявка',$message);self::redirect($redirect,['r'=>'ok']);}catch(Throwable $e){error_log('Trofy AppForms: '.$e->getMessage());self::redirect($redirect,['r'=>'err']);}
    }

    public static function render_applications(): string {
        try{$settings=self::settings();}catch(Throwable $e){return '<div class="trofy-alert">Ошибка подключения к заявкам.</div>'; }if(!$settings)return '<div class="trofy-alert">Не удалось загрузить настройки.</div>';if(!self::has_public_access()){$error=isset($_GET['login'])?'<div class="trofy-login-error">Неверный пароль.</div>':'';$redirect=self::applications_url($settings);return '<div class="trofy-login"><div class="trofy-login-icon">🔒</div><h2>Доступ к заявкам</h2><p>Введите пароль, чтобы посмотреть список экипажей.</p>'.$error.'<form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="trofy_public_login"><input type="hidden" name="redirect_to" value="'.esc_attr($redirect).'"><input type="hidden" name="season_id" value="'.(int)$settings['season_id'].'"><input type="hidden" name="event_id" value="'.(int)$settings['event_id'].'"><input type="password" name="password" placeholder="Пароль" autocomplete="current-password" required><button type="submit">Показать заявки</button></form></div>';}
        $rows=self::get_applications((int)$settings['season_id'],(int)$settings['event_id']);ob_start();?><div class="trofy-public"><div class="trofy-public-head"><div><span>СПИСОК ЗАЯВОК</span><h2><?php echo esc_html($settings['event_name']); ?></h2><p><?php echo esc_html($settings['season_name']); ?></p></div><div class="trofy-public-actions"><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=trofy_export&scope=public&season_id='.(int)$settings['season_id'].'&event_id='.(int)$settings['event_id']),'trofy_export')); ?>">↓ Excel</a><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=trofy_public_logout&redirect_to='.rawurlencode(self::applications_url($settings))),'trofy_public_logout')); ?>">Выйти</a></div></div><div class="trofy-public-count">Всего экипажей: <strong><?php echo count($rows); ?></strong></div><div class="trofy-public-grid"><?php foreach($rows as $i=>$row): ?><article><div class="trofy-card-top"><span>#<?php echo $i+1; ?></span><b><?php echo esc_html($row['class']); ?></b></div><h3><?php echo esc_html($row['namePilot']); ?></h3><p><?php echo esc_html($row['nameShturman']); ?></p><div>🚙 <?php echo esc_html($row['car']); ?></div><div>📍 <?php echo esc_html($row['city']); ?></div><div>📞 <a href="tel:<?php echo esc_attr($row['tel']); ?>"><?php echo esc_html($row['tel']); ?></a></div></article><?php endforeach; ?></div></div><?php return ob_get_clean();
    }

    public static function public_login(): void { $redirect=!empty($_POST['redirect_to'])?esc_url_raw(wp_unslash($_POST['redirect_to'])):self::applications_url();if(!wp_validate_redirect($redirect,self::applications_url()))$redirect=self::applications_url();try{$settings=self::settings();$password=isset($_POST['password'])?(string)wp_unslash($_POST['password']):'';if(!$settings||!$password)throw new RuntimeException();$stored=(string)$settings['pass'];$valid=wp_check_password($password,$stored);if(!$valid&&$stored!==''&&hash_equals($stored,$password)){$hash=wp_hash_password($password);$stmt=self::db()->prepare('UPDATE appsettings SET pass=? WHERE id=1');$stmt->bind_param('s',$hash);$stmt->execute();$valid=true;}if(!$valid)throw new RuntimeException();self::issue_public_access();self::redirect($redirect);}catch(Throwable $e){self::redirect($redirect,['login'=>'error']);} }
    public static function public_logout(): void {if(isset($_REQUEST['_wpnonce']))check_admin_referer('trofy_public_logout');$redirect=!empty($_REQUEST['redirect_to'])?esc_url_raw(wp_unslash($_REQUEST['redirect_to'])):self::applications_url();if(!wp_validate_redirect($redirect,self::applications_url()))$redirect=self::applications_url();self::revoke_public_access();self::redirect($redirect);}

    public static function export_excel(): void {
        $is_admin=is_user_logged_in()&&current_user_can('manage_options');if($is_admin)check_admin_referer('trofy_export');elseif(!self::has_public_access())wp_die('Доступ запрещён.','Доступ запрещён',['response'=>403]);
        try{$settings=self::settings();if(!$settings)wp_die('Настройки не найдены.');$rows=self::get_applications((int)$settings['season_id'],(int)$settings['event_id']);$path=__DIR__.'/PHPExcel/Classes/PHPExcel.php';if(!file_exists($path))wp_die('Библиотека PHPExcel не найдена.');require_once $path;$xls=new PHPExcel();$sheet=$xls->setActiveSheetIndex(0);$sheet->setTitle('Заявки');$sheet->setCellValue('A1','Заявки — '.$settings['event_name'].' '.$settings['season_name']);$sheet->mergeCells('A1:H1');$headers=['№','Время','Класс','ФИО пилота','ФИО штурмана','Город','Автомобиль','Телефон'];foreach($headers as $i=>$header)$sheet->setCellValueByColumnAndRow($i,2,$header);foreach($rows as $i=>$row){$r=$i+3;$values=[$i+1,$row['formatted_time'],$row['class'],$row['namePilot'],$row['nameShturman'],$row['city'],$row['car'],$row['tel']];foreach($values as $c=>$v)$sheet->setCellValueByColumnAndRow($c,$r,$v);}foreach(range('A','H') as $col)$sheet->getColumnDimension($col)->setAutoSize(true);$sheet->getStyle('A1:H2')->getFont()->setBold(true);while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="applications-'.date('Y-m-d-H-i').'.xlsx"');header('Cache-Control: max-age=0');PHPExcel_IOFactory::createWriter($xls,'Excel2007')->save('php://output');exit;}catch(Throwable $e){error_log('Trofy export: '.$e->getMessage());wp_die('Не удалось сформировать Excel. Проверьте журнал ошибок.');}
    }

    private static function frontend_css(): string { return <<<'CSS'
.trofy-form,.trofy-login,.trofy-public{max-width:1100px;margin:30px auto;font-family:inherit}.trofy-form{max-width:620px;padding:28px;background:#fff;border:1px solid #e8e8e8;border-radius:18px;box-shadow:0 10px 35px rgba(0,0,0,.06)}.trofy-form-head{margin-bottom:25px}.trofy-form-head span,.trofy-public-head span{font-size:12px;font-weight:700;letter-spacing:.12em;opacity:.55}.trofy-form-head h2,.trofy-public-head h2{margin:5px 0;font-size:28px}.trofy-form-head p,.trofy-public-head p{margin:0;opacity:.6}.trofy-input{margin:18px 0}.trofy-input label{display:block;font-weight:600;margin-bottom:7px}.trofy-input input,.trofy-login input{width:100%;box-sizing:border-box;padding:12px 14px;border:1px solid #d9dce1;border-radius:10px;background:#fff;font-size:16px}.trofy-radios{display:grid;grid-template-columns:1fr 1fr;gap:8px}.trofy-radios label{margin:0}.trofy-radios input{display:none}.trofy-radios span{display:block;padding:12px;border:1px solid #d9dce1;border-radius:10px;cursor:pointer;text-align:center}.trofy-radios input:checked+span{border-color:#222;background:#222;color:#fff}.trofy-submit,.trofy-login button{width:100%;padding:13px 18px;border:0;border-radius:10px;background:#222;color:#fff;font-size:16px;font-weight:700;cursor:pointer}.trofy-submit span{float:right}.trofy-hp{position:absolute!important;left:-10000px!important}.trofy-closed,.trofy-alert{padding:25px;border-radius:14px;background:#f5f5f5}.trofy-login{max-width:420px;text-align:center;padding:35px 25px;border:1px solid #e8e8e8;border-radius:18px;box-shadow:0 10px 35px rgba(0,0,0,.06)}.trofy-login-icon{font-size:30px}.trofy-login p{opacity:.65}.trofy-login form{display:grid;gap:12px;text-align:left}.trofy-login-error{padding:10px;background:#fff0f0;color:#b42318;border-radius:8px;margin:12px 0}.trofy-public-head{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:15px}.trofy-public-actions{display:flex;gap:8px}.trofy-public-actions a{padding:9px 13px;border:1px solid #ddd;border-radius:9px;text-decoration:none}.trofy-public-count{margin:15px 0;color:#666}.trofy-public-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.trofy-public-grid article{padding:18px;border:1px solid #e7e7e7;border-radius:14px;background:#fff}.trofy-card-top{display:flex;justify-content:space-between;font-size:12px;opacity:.65}.trofy-public-grid h3{margin:12px 0 3px}.trofy-public-grid p{margin:0 0 14px;opacity:.65}.trofy-public-grid article div:not(.trofy-card-top){margin-top:7px}@media(max-width:700px){.trofy-form{padding:20px;margin:15px 0}.trofy-form-head h2,.trofy-public-head h2{font-size:22px}.trofy-radios{grid-template-columns:1fr}.trofy-public-head{align-items:flex-start;flex-direction:column}.trofy-public-actions{width:100%}.trofy-public-actions a{flex:1;text-align:center}.trofy-public-grid{grid-template-columns:1fr}}
CSS; }

    private static function admin_css(): string { return <<<'CSS'
.trofy-admin{max-width:1400px}.trofy-admin-head{display:flex;justify-content:space-between;align-items:center;margin:25px 0}.trofy-admin-head h1{font-size:32px;margin:2px 0}.trofy-admin-head p{margin:0;color:#646970}.trofy-eyebrow{font-size:11px;letter-spacing:.15em;color:#777}.trofy-export{display:flex!important;align-items:center;gap:6px}.trofy-settings-card{display:flex;gap:16px;align-items:end;background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:20px;margin-bottom:16px}.trofy-field{flex:1}.trofy-field label{display:block;font-weight:600;margin-bottom:6px}.trofy-field select,.trofy-field input{width:100%;min-height:40px}.trofy-field small{display:block;color:#777;margin-top:4px}.trofy-save{min-height:40px}.trofy-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:16px 0}.trofy-stats div{background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:16px}.trofy-stats strong{display:block;font-size:25px}.trofy-stats span{color:#646970}.trofy-toolbar{display:flex;gap:10px;margin:16px 0}.trofy-toolbar input{flex:1;min-height:40px;padding:0 12px}.trofy-toolbar select{min-width:170px}.trofy-table-wrap{background:#fff;border:1px solid #e2e4e7;border-radius:12px;overflow:auto}.trofy-table{width:100%;border-collapse:collapse}.trofy-table th,.trofy-table td{padding:12px 13px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}.trofy-table th{font-size:12px;text-transform:uppercase;color:#646970;background:#f6f7f7;white-space:nowrap}.trofy-table tbody tr:hover{background:#f8f9fa}.trofy-class{display:inline-block;padding:4px 8px;border-radius:99px;background:#f0f0f1;font-size:12px}.trofy-empty{text-align:center!important;padding:40px!important;color:#777}.trofy-delete{padding:0!important}.trofy-admin #trofy-admin-message{margin-top:12px}@media(max-width:900px){.trofy-settings-card{flex-wrap:wrap}.trofy-field{min-width:45%}.trofy-stats{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.trofy-admin-head{align-items:flex-start;gap:15px;flex-direction:column}.trofy-settings-card{display:block}.trofy-field,.trofy-save{width:100%;margin-bottom:12px}.trofy-stats{grid-template-columns:repeat(2,1fr)}.trofy-toolbar{display:block}.trofy-toolbar>*{width:100%;margin-bottom:8px}.trofy-table-wrap{border:0;background:transparent;overflow:visible}.trofy-table,.trofy-table tbody{display:block}.trofy-table thead{display:none}.trofy-table tr{display:block;background:#fff;border:1px solid #e2e4e7;border-radius:12px;margin-bottom:10px;padding:8px}.trofy-table td{display:flex;justify-content:space-between;gap:15px;border:0;padding:8px}.trofy-table td:before{content:attr(data-label);font-weight:600;color:#646970}.trofy-table td:last-child{justify-content:flex-end}.trofy-table td:last-child:before{display:none}}
CSS; }

    private static function admin_js(): string { return <<<'JS'
jQuery(function($){const cfg=window.TrofyAppForms;$('#trofy-season').on('change',function(){const id=$(this).val();$('#trofy-event').html('<option>Загрузка...</option>');$.post(cfg.ajaxurl,{action:'trofy_load_events',season_id:id,nonce:cfg.nonce},function(r){if(!r.success){$('#trofy-event').html('<option>Ошибка</option>');return}let h='';r.data.forEach(e=>h+='<option value="'+e.event_id+'">'+$('<div>').text(e.event_name).html()+'</option>');$('#trofy-event').html(h);});});$('#trofy-save').on('click',function(){const b=$(this);b.prop('disabled',true).text('Сохранение...');$.post(cfg.ajaxurl,{action:'trofy_save_settings',season_id:$('#trofy-season').val(),event_id:$('#trofy-event').val(),pass:$('#trofy-pass').val(),nonce:cfg.nonce},function(r){b.prop('disabled',false).text('Сохранить');$('#trofy-admin-message').html('<div class="notice '+(r.success?'notice-success':'notice-error')+'"><p>'+r.data+'</p></div>');if(r.success)$('#trofy-pass').val('');});});$('#trofy-search,#trofy-class').on('input change',function(){const q=($('#trofy-search').val()||'').toLowerCase(),c=$('#trofy-class').val();$('#trofy-rows tr').each(function(){const ok=($(this).data('search')||'').toString().includes(q)&&(c===''||$(this).data('class')===c);$(this).toggle(ok);});});$(document).on('click','.trofy-delete',function(){if(!confirm('Удалить заявку?'))return;const b=$(this),id=b.data('id');$.post(cfg.ajaxurl,{action:'trofy_delete_application',id:id,nonce:cfg.nonce},function(r){if(r.success)b.closest('tr').fadeOut(200,function(){$(this).remove();});else alert(r.data||'Ошибка');});});});
JS; }
}
Trofy_AppForms::init();
