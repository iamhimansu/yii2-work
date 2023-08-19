<?php

namespace app\models;

use Closure;
use Exception;

class Csv2Sql
{
    private $_file = '';
    private $_tables = [];
    private $_sql = [];
    private $_skipRows = 0;
    private $_readLength = null;
    private $_headers = [];
    private $_rowCount = 0;
    private $_separator = ',';
    private $_columns = [];
    private $_columnIndexMap = [];
    private $_copyTable = false;
    private $_endSeparator = "\n";

    public function __construct($file)
    {
        $this->setFile($file);
    }

    /**
     * @throws Exception
     */
    public function setFile($file)
    {
        if (!file_exists($file)) {
            throw new Exception('File not found.');
        }
        $this->_file = $file;
        return $this;
    }

    /**
     * Prepares statement LIKE
     *   INSERT INTO `table`
     *      SELECT 'a','b'
     *      UNION ALL
     *      SELECT 'c','d'
     *
     * @param $table
     * @return void
     */
    public function copyInto($table)
    {
        $this->_copyTable = true;
        $this->insertInto($table);
        $this->_endSeparator = "UNION ALL\n";
    }

    /**
     * Creates multiple insert statements
     * @param $table
     * @param bool $multipleInsert
     * @return void
     */
    public function insertInto($table, bool $multipleInsert = true)
    {
        $this->_endSeparator = ",\n";
        $this->_sql[] = "INSERT INTO `$table`";
        $this->_sql[] = "\n";
    }

    /**
     * Creates an array of columns to write in csv
     * Normalizes columns with columnId => columnName
     * @param $columns
     * @return void
     */
    public function select($columns)
    {
        if (!empty($columns)) {
            foreach ($columns as $columnId => $column) {
                if ($column instanceof Closure) {
                    $this->_columns[$columnId] = $column;
                } else {
                    $this->_columns[$column] = $column;
                }
            }
        }
    }

    public function getRawSql()
    {
        return $this->processSql();
    }

    /**
     * @throws Exception
     */
    private function processSql(): string
    {
        $this->run();
        return rtrim(implode('', $this->_sql), $this->_endSeparator) . ';';
    }

    /**
     * Processes the file
     * @return bool
     * @throws Exception
     */
    private function run(): bool
    {
        if (($handle = fopen($this->_file, 'r')) !== false) {

            while (($row = fgetcsv($handle, $this->_readLength, $this->_separator)) !== false) {

                $rowObject = new Row($this->_rowCount, $row);

                if (empty($this->_headers)) {
                    $this->createHeaders($row);

                    $this->_sql[] = '(`' . implode('`,`', $this->_headers) . '`)';

                    if (!$this->_copyTable) {
                        $this->_sql[] = "\n VALUES \n";
                    }

                } else {

                    if ($this->_rowCount < $this->_skipRows) {
                        $this->_rowCount++;
                        continue;
                    }

                    /**
                     * process rows
                     */
                    $_sqlColumns = $this->_columnIndexMap;

                    foreach ($row as $columnIndex => $cellData) {

                        if (isset($this->_headers[$columnIndex])) {
                            $name = $this->_headers[$columnIndex];
                            $cellObject = new Cell($name, $cellData);
                            if (($callback = $this->_columns[$name]) instanceof Closure) {
                                $_sqlColumns[$columnIndex] = call_user_func_array($callback, [$rowObject, $cellObject]);
                            } else {
                                $_sqlColumns[$columnIndex] = $cellObject->value;
                            }
                        }
                    }
                    if (!empty($_sqlColumns)) {
                        $this->generateStatement($_sqlColumns);
                    }
                }
                $this->_rowCount++;
            }
            fclose($handle);
            return true;
        }
        return false;
    }

    /**
     * Generates headers based upon given columns
     * @param $row
     * @return void
     */
    private function createHeaders($row)
    {
        if (empty($this->_columns)) {
            foreach ($row as $index => $column) {
                $this->_columns[$column] = $column;
                $this->_headers[$index] = $column;
                $this->_columnIndexMap[$index] = null;
            }
            return;
        }
        foreach ($this->_columns as $columnId => $column) {
            if (false !== ($columnIndex = array_search($columnId, $row))) {
                $this->_headers[$columnIndex] = $columnId;
                $this->_columnIndexMap[$columnIndex] = null;
            }
        }
    }

    /**
     * @throws Exception
     */
    public function output($file)
    {
        $res = fopen($file, 'w+');
        if (fwrite($res, $this->processSql())) {
            fclose($res);
        } else {
            throw new Exception('Could not open file for writing.');
        }
    }

    /**
     * @param int $readLength
     */
    public function setReadLength(int $readLength)
    {
        $this->_readLength = $readLength;
    }

    /**
     * @param int $skipRows
     */
    public function setSkipRows(int $skipRows)
    {
        $this->_skipRows = $skipRows;
    }

    private function generateStatement($data)
    {
        if ($this->_copyTable) {
            //(SELECT NULL,'klop','polk')
            $this->_sql[] = '(SELECT "' . implode('","', $data) . '") ' . "\nUNION ALL";
        } else {
            //(SELECT NULL,'klop','polk')
            $this->_sql[] = '("' . implode('","', $data) . '"),';
        }
        $this->_sql[] = "\n";
    }
}


class Row
{

    public $index;
    public $value;

    public function __construct($index, $value)
    {
        $this->index = $index;
        $this->value = $value;
    }
}

class Cell
{
    public $columnName;
    public $value;
    public $x; //A
    public $y; //4
    public $name; // A4

    public function __construct($name, $value)
    {
        $this->columnName = $name;
        $this->value = $value;
    }
}













