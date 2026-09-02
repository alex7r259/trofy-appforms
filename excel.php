<?php
// Конфигурация
const DB_HOST = 'localhost';
const DB_USER = 'j84588200_result';
const DB_PASS = '?fYt3K7yGaqv';
const DB_NAME = 'j84588200_results';

try {
    // Подключение PHPExcel
    require_once __DIR__ . '/PHPExcel/Classes/PHPExcel.php';
    
    // Создание документа Excel
    $xls = new PHPExcel();
    $sheet = $xls->setActiveSheetIndex(0);
    $sheet->setTitle('Список заявок');
    
    // Настройка стилей
    $headerStyle = [
        'font' => ['bold' => true],
        'alignment' => ['vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER],
        'fill' => [
            'type' => PHPExcel_Style_Fill::FILL_SOLID,
            'color' => ['rgb' => 'D9D9D9']
        ],
        'borders' => [
            'allborders' => [
                'style' => PHPExcel_Style_Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ];
    
    $titleStyle = [
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => ['horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT,
                        'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER]
    ];
    
    $bodyStyle = [
        'borders' => [
            'allborders' => [
                'style' => PHPExcel_Style_Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ];
    
    // Подключение к БД и получение данных
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $query = "SELECT app.event_id, event_name, app.season_id, season_name 
              FROM appsettings app
              JOIN Seasons s ON app.season_id = s.season_id
              JOIN Events e ON app.event_id = e.event_id";
    $result = mysqli_query($db, $query);
    $rows_id = mysqli_fetch_all($result, MYSQLI_ASSOC);
    
    // Добавление названия события и сезона в первую строку
    $sheet->setCellValue('A1', 'Заявки "' . $rows_id[0]['event_name'] . ' ' . $rows_id[0]['season_name'] . '"');
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1')->applyFromArray($titleStyle);
    $sheet->getRowDimension(1)->setRowHeight(25);
    
    // Настройка колонок
    $columns = [
        'A' => ['title' => 'Время', 'width' => 20],
        'B' => ['title' => 'Класс', 'width' => 15],
        'C' => ['title' => 'ФИО пилота', 'width' => 25],
        'D' => ['title' => 'ФИО штурмана', 'width' => 25],
        'E' => ['title' => 'Город', 'width' => 20],
        'F' => ['title' => 'Автомобиль', 'width' => 20],
        'G' => ['title' => 'Телефон', 'width' => 20]
    ];
    
    // Установка ширины колонок
    foreach ($columns as $col => $settings) {
        $sheet->getColumnDimension($col)->setWidth($settings['width']);
    }
    
    // Заполнение заголовков (теперь начиная со строки 2)
    $rowIndex = 2;
    foreach ($columns as $col => $settings) {
        $sheet->setCellValue($col . $rowIndex, $settings['title']);
    }
    
    $sheet->getStyle('A2:G2')->applyFromArray($headerStyle);
    $sheet->getRowDimension(2)->setRowHeight(30);
    
    // Получение данных заявок
    $query = "SELECT 
              DATE_FORMAT(time, '%d.%m.%Y %H:%i') as formatted_time,
              class, namePilot, nameShturman, city, car, tel 
              FROM appparticipation 
              WHERE season_id = ".$rows_id[0]['season_id']." AND event_id = ".$rows_id[0]['event_id']."
              ORDER BY time DESC";
    
    $result = $db->query($query);
    
    // Заполнение данных
    while ($row = $result->fetch_assoc()) {
        $rowIndex++;
        $sheet->setCellValue('A' . $rowIndex, $row['formatted_time']);
        $sheet->setCellValue('B' . $rowIndex, $row['class']);
        $sheet->setCellValue('C' . $rowIndex, $row['namePilot']);
        $sheet->setCellValue('D' . $rowIndex, $row['nameShturman']);
        $sheet->setCellValue('E' . $rowIndex, $row['city']);
        $sheet->setCellValue('F' . $rowIndex, $row['car']);
        $sheet->setCellValue('G' . $rowIndex, $row['tel']);
    }
    
    // Применение стилей к данным
    if ($rowIndex > 2) {
        $sheet->getStyle('A3:G' . $rowIndex)->applyFromArray($bodyStyle);
    }
    
    // Вывод файла
    $filename = "заявки_" . date('Y-m-d_H-i-s') . ".xlsx";
    $objWriter = PHPExcel_IOFactory::createWriter($xls, 'Excel2007');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $objWriter->save('php://output');

} catch (Exception $e) {
    // Логирование ошибки
    error_log('Excel generation error: ' . $e->getMessage());
    
    // Вывод сообщения об ошибке
    die('Произошла ошибка при генерации файла. Пожалуйста, попробуйте позже.');
}
?>