<?php
include 'gs.php';
try {
    $mysql = new PDO("mysql:dbname=".SQL_DBASE.";host=".SQL_HOST, SQL_USER, SQL_PASS);
    $mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mysql->exec("SET CHARACTER SET utf8");      // Sets encoding UTF-8
} catch (PDOException $e) {
    echo 'Error :'.$e->getMessage();
}

$dd = json_decode(file_get_contents("php://input"), true); // Datas from html form

$ids = $dd['refs'];
if (!empty($ids)) {

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><root />');
    $csv = array_map('str_getcsv', file('../csv/data-multi.csv')); // Directory contains CSV-files

    $resultFields    = [];
    $joins           = [];
    $fieldsForSelect = [];
    $whereStr        = '';
    $whereValue      = '';
    $joinStr         = '';
    $parentTableName = '';
    $selectStr       = 'SELECT ';
    $xmlFieldsArr    = [];

    foreach ($csv as $elementCsv) {
        $exists     = true;
        $elementArr = explode(';', $elementCsv[0]);
        if (count($elementArr) >= 2) {
            $xmlFieldName = trim($elementArr[0]);

            $tableFieldName = explode(':', $elementArr[1]);
            if (array_key_exists(0, $tableFieldName) && array_key_exists(1, $tableFieldName)) {
                $tableName = trim($tableFieldName[0]);
                $fieldName = trim($tableFieldName[1]);
            } else {// Check empty fields
                $exists = false;
            }
        } else {
            $exists = false;
        }
        if ($exists) {//Check database fields
            $cheskingSql = "SHOW COLUMNS FROM $tableName LIKE :fieldName";
            $resChecikng = $mysql->prepare($cheskingSql);
            $resChecikng->bindParam(':fieldName', $fieldName);
            $resChecikng->execute();

            $exists = ($resChecikng->fetchColumn()) ? TRUE : FALSE;
        }
        if ($exists) {
            $xmlFieldsArr[$xmlFieldName] = $tableName.'.'.$fieldName;

            $joinIdTable = explode(':', $elementArr[2]);
            if (!empty($joinIdTable) && ($joinIdTable[3] == 'primary')) {
                $whereStr        = trim($joinIdTable[0]).'.'.trim($joinIdTable[1]).'=:primary';
                $whereValue      = trim($joinIdTable[2]);
                $parentTableName = trim($joinIdTable[0]);
            } elseif (!empty($joinIdTable)) {
                $parentFieldTable = trim($joinIdTable[1]);
                if (!array_key_exists($parentFieldTable, $joins)) {
                    $joins[$parentFieldTable] = [
                        'parent_field' => $joinIdTable[1],
                        'join_field'   => $joinIdTable[2],
                        'join_table'   => $tableName
                    ];
                }
            }
        }
    }


    if (empty($parentTableName)) {//Check PRIMARY key
        $parentTableName = 'listings';
        $whereStr        = $parentTableName.'.id IN('.implode(',', $ids).') ';
//        exit('Error: CSV-file doesn\'t contain primary table value! Check pls');
    }

    /** GENERATE SQL STRING * */
    $i        = 0;
    $numItems = count($xmlFieldsArr);
    foreach ($xmlFieldsArr as $xmlFieldName => $tableFieldData) {
        $selectStr .= $tableFieldData.' AS '.$xmlFieldName;
        if (++$i !== $numItems) {
            $selectStr .= ',';
        } else {
            $selectStr                .= ', '.$parentTableName.'.id AS objectid FROM '.$parentTableName;
            $xmlFieldsArr['objectid'] = $parentTableName.'.id';
        }
    }
	/*
    foreach ($joins as $joinIdTable) {
        if (trim($joinIdTable['join_table']) != trim($parentTableName)) {
            $selectStr .= ' LEFT JOIN '.$joinIdTable['join_table'].' ON '.
                $parentTableName.'.'.$joinIdTable['parent_field'].'='.$joinIdTable['join_table'].'.'.$joinIdTable['join_field'];
        }
    }
	*/
    $selectStr .= ' WHERE '.$whereStr;
	$fp = fopen("json/SQLFUCKED.json", 'w');
	fwrite($fp, json_encode($selectStr));
	fclose($fp);
    $r = $mysql->prepare($selectStr);
    if (!empty($whereValue)) {
        $r->bindParam(':primary', $whereValue); //, PDO::PARAM_STR
    }
    $r->execute();
    $resultAssoc = $r->fetchAll(PDO::FETCH_ASSOC);
	$fp = fopen("json/SQLRESULTS.json", 'w');
	fwrite($fp, json_encode($resultAssoc));
	fclose($fp);
    /** GENERATE XML FROM DATA * */
    foreach ($resultAssoc as $res) {
        $object = $xml->addChild('object');
        foreach ($xmlFieldsArr as $xmlFieldName => $tableFieldData) {
            $object->addChild($xmlFieldName, $res[$xmlFieldName]);
        }
    }
    $xmlFileName = 'data-'.date("Y-m-d-H-i-s").'.xml';

    $dom                     = new DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput       = true;
    $dom->loadXML($xml->asXML());
    $dom->save('../xml/'.$xmlFileName); //Save directory and name

    $data = array(
        'link'    => $xmlFileName,
        'content' => file_get_contents('../xml/'.$xmlFileName)//return the xml content for downloading immediately
    );
} else {
    $data = array(
        'error' => 'Basket is EMPTY!',
    );
}
exit(json_encode($data));
