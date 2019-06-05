<?php

const DB_HOST = '127.0.0.1';
const DB_DATABASE   = 'ruslania';
const DB_USER = 'ruslania';
const DB_PASSWORD = 'ruslania';
const DB_CHARSET = 'utf8';

const REPORT_FILE = 'report.txt';

const ISBN_EXIST = 1;
const ISBN_NEW = 2;
const ISBN_WRONG = 3;
        
main();

function main(){
	
        if (file_exists(REPORT_FILE)) {
            unlink(REPORT_FILE);
        }
        
        $reportFileDescriptor = fopen(REPORT_FILE, 'w+') or die("не удалось создать файл отчета");
        $headStr = "Идентификатор строки\tНайденное совпадение\tЦифровое представление\tРезультат\r\n";
        fwrite($reportFileDescriptor, iconv('utf-8', 'windows-1251', $headStr));

    	$dbh = getDbConnection();

        // ограничиваем выборку строками с минимум 10 цифрами
	$query = 'select id, description_ru as description, isbn, isbn, isbn2, isbn3, isbn4, isbn_wrong 
                from books_catalog 
                where description_ru regexp "\\\\b\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d(\\\\S*\\\\d\\\\S*\\\\d\\\\S*\\\\d)?\\\\b"
        ';
        
        $sth = $dbh->prepare($query) ;
	$sth->execute();
                        
	while ($row = $sth->fetch()) {
            
            recordHandler($row, $reportFileDescriptor, $dbh);            
            
        }
        
        fclose($reportFileDescriptor);
}

function getDbConnection()
{
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_DATABASE.";charset=".DB_CHARSET;
    $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $dbh = new PDO($dsn, DB_USER, DB_PASSWORD, $opt);
    
    return $dbh;
}

function recordHandler($row, $reportFileDescriptor, $dbh)
{
    $matches = [];
    
    if (preg_match_all('/\b((?:\d\S*){9}\d(?:(?:\S*\d){3})?)\b/', $row['description'], $matches)) {
        //echo var_dump($matches);
        
        foreach ($matches[1] as $match) {
            
            $matchDigits = preg_replace('/\D/', '', $match);
            
            $matchDigitsLen = strlen($matchDigits);
            if ($matchDigitsLen != 10 && $matchDigitsLen != 13) {
            //if (!$matchDigits) {
                continue;
            }       

            //Проверить не входит ли найденный isbn в любое из isbn-полей.
            if ($matchDigits == preg_replace('/\D/', '', $row['isbn'])) {

                printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, ISBN_EXIST, 'isbn');
            } 
            elseif ($matchDigits == preg_replace('/\D/', '', $row['isbn2'])) {

                printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, ISBN_EXIST, 'isbn2');
            } 
            elseif ($matchDigits == preg_replace('/\D/', '', $row['isbn3'])) {

                printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, ISBN_EXIST, 'isbn3');
            }
            elseif (paternInStrDelimiter($row['isbn4'], $matchDigits)) {

                printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, ISBN_EXIST, 'isbn4');
            }
            elseif (paternInStrDelimiter($row['isbn_wrong'], $matchDigits)) {

                printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, ISBN_EXIST, 'isbn_wrong');
            }
            //Если нет, то проверить контрольную цифру и разделители
            else {
                // Если контрольная цифра правильная и разделители "-" или их вообще нет
                if (checkSumIsValid($matchDigits) && delimiterIsValid($match)) {
                    
                    $field = saveISBN($dbh, $row, $match);
                    printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, ISBN_NEW, $field);

                } 
                // Если цифра неправильная или есть левые разделители
                else {
                    
                    saveISBNWrong($dbh, $row, $match);
                    printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, ISBN_WRONG);
                }

            }
        }
    }
}

function paternInStrDelimiter($str, $pattern) {    
    
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

function printReportInCSV($reportFileDescriptor, $row, $match, $matchDigits, $status, $field = null) {
    
    if ($status == ISBN_EXIST) {
        $status_txt = 'найден в поле "' . $field . '"';
        
    }elseif ($status == ISBN_NEW) {
        $status_txt = 'новое значение, сохранен в поле "' . $field . '"';
        
    }elseif ($status == ISBN_WRONG) {
        $status_txt = 'ошибочный код, сохранен в поле "isbn_wrong"';
    }
    
    $str = $row['id'] . "\t" . $match . "\t" . $matchDigits . "\t" . $status_txt . "\r\n";
    fwrite($reportFileDescriptor, iconv('utf-8', 'windows-1251', $str));
        
}

function saveISBN($dbh, &$row, $match) {
    
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
            
    $query = 'update books_catalog set ' . $field . '=?
            
            where id=?';
    $sth = $dbh->prepare($query);
    $sth->execute([$isbnContent, $row['id']]);    
    
    return $field;
}

function saveISBNWrong($dbh, &$row, $match) {
    
    $isbnWrong = $row['isbn_wrong'] ? $row['isbn_wrong'] . ',' . $match : $match;
    
    $row['isbn_wrong'] = $isbnWrong;
    
    $query = 'update books_catalog set isbn_wrong=? where id=?';
    $sth = $dbh->prepare($query);
    $sth->execute([$isbnWrong, $row['id']]);
        
}

function checkSumIsValid($isbn) {
    
    if (strlen($isbn) == 10) {
        return checkSumISBN($isbn);
        
    } elseif (strlen($isbn) == 13) {
        return checkSumEAN13($isbn);
    }
}

function delimiterIsValid($isbnRaw) {
    
    return !preg_match('/[^\d\-]/', $isbnRaw);
}

function checkSumISBN($isbn) {
    
    $c = [10, 9, 8, 7, 6, 5, 4, 3, 2];
    
    $digits = str_split($isbn);
    $sum = 0;
    for ($i=0; $i<9; $i++) {
        $sum += intval($digits[$i]) * $c[$i];        
    }
    
    $checkSum = 11 - $sum % 11;
    
    return intval($digits[9]) == $checkSum;
}

function checkSumEAN13($isbn) {
    
    $c = [1, 3, 1, 3, 1, 3, 1, 3, 1, 3, 1, 3];
    
    $digits = str_split($isbn);
    $sum = 0;
    for ($i=0; $i<12; $i++) {
        $sum += intval($digits[$i]) * $c[$i];        
    }
    
    $checkSum = $sum % 10;
    
    return intval($digits[12]) == $checkSum;
}