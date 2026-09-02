jQuery(document).ready(function($) {
    // Обработчик изменения сезона
    $('#season_id').on('change', function() {
        var seasonId = $(this).val();
        
        
        $.ajax({
            url: app_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_table',
                id: season_id, 
                table: 'events',
                col: 'season_id',
                nonce: app_ajax.nonce
            },
            beforeSend: function() {
                $('#event_id').html('<option value="">Загрузка...</option>');
            },
            success: function(response) {
                if (response.success) {
                    var options = '';
                    $.each(response.data, function(index, event) {
                        options += '<option value="' + event.event_id + '">' + event.event_name + '</option>';
                    });
                    $('#event_id').html(options);
                } else {
                    $('#app-message').html('<div class="error"><p>Ошибка: ' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $('#app-message').html('<div class="error"><p>Ошибка AJAX: ' + error + '</p></div>');
            }
        });
    });
    
    // Обработчик отправки формы
    $('#app-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: app_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_app_settings',
                season_id: $('#season_id').val(),
                event_id: $('#event_id').val(),
                pass: $('#pass').val(),
                nonce: app_ajax.nonce
            },
            beforeSend: function() {
                $('#app-message').html('<p>Сохранение...</p>');
            },
            success: function(response) {
                if (response.success) {
                    $('#app-message').html('<div class="updated"><p>Настройки сохранены!</p></div>');
                } else {
                    $('#app-message').html('<div class="error"><p>Ошибка: ' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $('#app-message').html('<div class="error"><p>Ошибка AJAX: ' + error + '</p></div>');
            }
        });
    });
});