<?php

namespace Isbn;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PDO;

class IsbnHandler {
    
    const ISBN_EXIST = 1;
    const ISBN_NEW = 2;
    const ISBN_WRONG = 3;

    private $dbh;
    
    private $spreadsheet;
    private $sheet;
    private $currentRow;
    
    private $db_host;
    private $db_database;
    private $db_user;
    private $db_password;
    private $db_charset;
    
    private $reportFile;
    
    /**
     * Конструктор
     * 
     * @param type $db_host
     * @param type $db_database
     * @param type $db_user
     * @param type $db_password
     * @param type $db_charset
     * @param type $reportFile
     */
    public function __construct($db_host, $db_database, $db_user, $db_password, $db_charset, $reportFile) {
        
        $this->db_host = $db_host;
        $this->db_database = $db_database;
        $this->db_user = $db_user;
        $this->db_password = $db_password;
        $this->db_charset = $db_charset;
        
        $this->reportFile = $reportFile;
    }
    
    /**
     * Основной обработчик
     */
    public function handler(){

        $this->createDbConnection();
        $this->createExcelObject();        
        
        // ограничиваем выборку строками с минимум 10 цифрами
        $query = 'select id, description_ru as description, isbn, isbn, isbn2, isbn3, isbn4, isbn_wrong 
                from books_catalog 
                where description_ru regexp "\\\\b\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d(\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d)?\\\\b"
        ';

        $sth = $this->dbh->prepare($query) ;
        $sth->execute();

        while ($row = $sth->fetch()) {

            $this->recordHandler($row);            

        }

        $writer = new Xlsx($this->spreadsheet);
        $writer->save($this->reportFile);
    }

    /**
     * Создает подключение к базе
     * 
     * @param type $db_host
     * @param type $db_database
     * @param type $db_user
     * @param type $db_password
     * @param type $db_charset
     * @return \PDO
     */
    private function createExcelObject()
    {
        $this->spreadsheet = new Spreadsheet();
        $this->sheet = $this->spreadsheet->getActiveSheet();
        
        $styleHeader = [
            'font' => [
                'bold' => true
            ]            
        ];
        
        $this->sheet->setCellValue('A1', 'Идентификатор строки')->getStyle('A1')->applyFromArray($styleHeader);
        $this->sheet->setCellValue('B1', 'Найденное совпадение')->getStyle('B1')->applyFromArray($styleHeader);
        $this->sheet->setCellValue('C1', 'Цифровое представление')->getStyle('C1')->applyFromArray($styleHeader);
        $this->sheet->setCellValue('D1', 'Результат')->getStyle('D1')->applyFromArray($styleHeader);
        
        $this->sheet->getColumnDimension('A')->setWidth(24);
        $this->sheet->getColumnDimension('B')->setWidth(24);
        $this->sheet->getColumnDimension('C')->setWidth(24);
        $this->sheet->getColumnDimension('D')->setWidth(128);
        
        $this->currentRow = 2;

    }
    
    /**
     * Создает подключение к базе
     * 
     */
    private function createDbConnection()
    {
        $dsn = "mysql:host=" . $this->db_host . ";dbname=" . $this->db_database . ";charset=" . $this->db_charset;
        $opt = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $this->dbh = new PDO($dsn, $this->db_user, $this->db_password, $opt);

    }

    /**
     * Обработчик одной записи
     * 
     * @param type $row
     */
    private function recordHandler($row)
    {
        $matches = [];

        if (preg_match_all('/\b((?:\d\S*){9}\d(?:(?:\S*\d){3})?)\b/', $row['description'], $matches)) {
            
            foreach ($matches[1] as $match) {

                $matchDigits = preg_replace('/\D/', '', $match);

                $matchDigitsLen = strlen($matchDigits);
                if ($matchDigitsLen != 10 && $matchDigitsLen != 13) {
                //if (!$matchDigits) {
                    continue;
                }       

                //Проверить не входит ли найденный isbn в любое из isbn-полей.
                if ($matchDigits == preg_replace('/\D/', '', $row['isbn'])) {

                    $this->addReportRecord($row, $match, $matchDigits, self::ISBN_EXIST, 'isbn');
                } 
                elseif ($matchDigits == preg_replace('/\D/', '', $row['isbn2'])) {

                    $this->addReportRecord($row, $match, $matchDigits, self::ISBN_EXIST, 'isbn2');
                } 
                elseif ($matchDigits == preg_replace('/\D/', '', $row['isbn3'])) {

                    $this->addReportRecord($row, $match, $matchDigits, self::ISBN_EXIST, 'isbn3');
                }
                elseif ($this->paternInStrDelimiter($row['isbn4'], $matchDigits)) {

                    $this->addReportRecord($row, $match, $matchDigits, self::ISBN_EXIST, 'isbn4');
                }
                elseif ($this->paternInStrDelimiter($row['isbn_wrong'], $matchDigits)) {

                    $this->addReportRecord($row, $match, $matchDigits, self::ISBN_EXIST, 'isbn_wrong');
                }
                //Если нет, то проверить контрольную цифру и разделители
                else {
                    // Если контрольная цифра правильная и разделители "-" или их вообще нет
                    if ($this->checkSumIsValid($matchDigits) && $this->delimiterIsValid($match)) {

                        $field = $this->saveISBN($row, $match);
                        $this->addReportRecord($row, $match, $matchDigits, self::ISBN_NEW, $field);

                    } 
                    // Если цифра неправильная или есть левые разделители
                    else {

                        $this->saveISBNWrong($row, $match);
                        $this->addReportRecord($row, $match, $matchDigits, self::ISBN_WRONG);
                    }

                }
            }
        }
    }

    /**
     * Проверяет вхождение $pattern в строку с разделителями $str
     * 
     * @param type $str
     * @param type $pattern
     * @return boolean
     */
    private function paternInStrDelimiter($str, $pattern) {    

        $list = preg_split('/,/', $str);

        $result = false;

        foreach ($list as $item) {
            $item = preg_replace('/\D/', '', $item);
            if ($item == $pattern) {
                $result = true;
                break;
            }
        }

        return $result;
    }

    /**
     * Добавляет строку в отчет
     * 
     * @param type $row
     * @param type $match
     * @param type $matchDigits
     * @param type $status
     * @param type $field
     */
    private function addReportRecord($row, $match, $matchDigits, $status, $field = null) {

        if ($status == self::ISBN_EXIST) {
            $status_txt = 'найден в поле "' . $field . '"';

        }elseif ($status == self::ISBN_NEW) {
            $status_txt = 'новое значение, сохранен в поле "' . $field . '"';

        }elseif ($status == self::ISBN_WRONG) {
            $status_txt = 'ошибочный код, сохранен в поле "isbn_wrong"';
        }

        $this->sheet->setCellValue('A' . $this->currentRow, $row['id']);
        $this->sheet->setCellValue('B' . $this->currentRow, $match)->getStyle('B' . $this->currentRow)->getNumberFormat()->setFormatCode( \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT );
        $this->sheet->setCellValue('C' . $this->currentRow, $matchDigits)->getStyle('C' . $this->currentRow)->getNumberFormat()->setFormatCode( \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT );
        $this->sheet->setCellValue('D' . $this->currentRow, $status_txt)->getStyle('D' . $this->currentRow)->getNumberFormat()->setFormatCode( \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT );
        
        $this->currentRow++;
                
    }

    /**
     * Сохраняет isbn в исходной записи и возвращает название поля в которое сохранил
     * 
     * @param array $row
     * @param type $match
     * @return string
     */
    private function saveISBN(&$row, $match) {

        $isbnContent = $match;

        if ($row['isbn2'] === '') {
            $field = 'isbn2';

        } elseif ($row['isbn3'] === '') {
            $field = 'isbn3';

        } else {

            $field = 'isbn4';

            if ($row['isbn4'] !== '') {
                $isbnContent = $row['isbn4'] . ',' . $isbnContent; 
            }

        }

        $row[$field] = $isbnContent;

        $query = 'update books_catalog set ' . $field . '=? where id=?';
        
        $sth = $this->dbh->prepare($query);
        $sth->execute([$isbnContent, $row['id']]);    

        return $field;
    }

    /**
     * Сохраняет ошибочный isbn
     * 
     * @param array $row
     * @param type $match
     */
    private function saveISBNWrong(&$row, $match) {

        $row['isbn_wrong'] = $row['isbn_wrong'] ? $row['isbn_wrong'] . ',' . $match : $match;

        $query = 'update books_catalog set isbn_wrong=? where id=?';
        $sth = $dbh->prepare($query);
        $sth->execute([$row['isbn_wrong'], $row['id']]);

    }

    /**
     * Проверяет валидность чек-суммы isbn
     * 
     * @param type $isbn
     * @return boolean
     */
    private function checkSumIsValid($isbn) {

        if (strlen($isbn) == 10) {
            return checkSumISBN($isbn);

        } elseif (strlen($isbn) == 13) {
            return checkSumEAN13($isbn);
        }
    }

    /**
     * Проверят isbn на разделители
     * 
     * @param type $isbnRaw
     * @return boolean
     */
    private function delimiterIsValid($isbnRaw) {

        return !preg_match('/[^\d\-]/', $isbnRaw);
    }

    /**
     * Проверяет чек сумму 10 значного кода
     * 
     * @param type $isbn
     * @return boolean
     */
    private function checkSumISBN($isbn) {

        $c = [10, 9, 8, 7, 6, 5, 4, 3, 2];

        $digits = str_split($isbn);
        $sum = 0;
        for ($i=0; $i<9; $i++) {
            $sum += intval($digits[$i]) * $c[$i];        
        }

        $checkSum = 11 - $sum % 11;

        return intval($digits[9]) == $checkSum;
    }

    /**
     * Проверяет чек сумму ean13
     * 
     * @param type $isbn
     * @return boolean
     */
    private function checkSumEAN13($isbn) {

        $c = [1, 3, 1, 3, 1, 3, 1, 3, 1, 3, 1, 3];

        $digits = str_split($isbn);
        $sum = 0;
        for ($i=0; $i<12; $i++) {
            $sum += intval($digits[$i]) * $c[$i];        
        }

        $checkSum = $sum % 10;

        return intval($digits[12]) == $checkSum;
    }
    
}