<?php

namespace uims\student\modules\slcm\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Yii;
use yii\db\ActiveRecord;

class Excel
{
    //Configs
    public $csv_columns = false;
    //CSV Configs
    public $csv_structure_or_data = 'data';
    public $csv_null = 'NULL';
    public $csv_separator = ',';
    public $csv_enclosed = '"';
    public $csv_escaped = '"';
    public $csv_terminated = "\n";
    public $csv_removeCRLF = true;
    private $fetch_next_batch = false;
    private $debug = false;

    //File Configuration
    private $filename = '';
    private $file_details;
    private $file_size = 0;
    //
    private $valid_extensions = ['csv'];
    private $queryOrDataProvider;
    private $chunkSize = 0;
    private $offset = 0;
    private $_start_time;

    //Columns manipulation
    private $column_names_substitute = [];
    private $column_value_substitutes = [];
    private $override_column_values = false;

    private $columns = [];
    private $select_columns = '*';
    private $db = null;
    private $order_of_columns = [];
    private $is_column_order_defined = false;
    private $_excluding_columns = [];
    private $test_count = 0;

    //Header is prepared
    private $header_is_prepared = false;

    /**
     * @param $queryOrDataProvider
     * @return Data
     */
    public static function export($queryOrDataProvider)
    {
        $_self = new self();
        $_self->queryOrDataProvider = $queryOrDataProvider;
        return $_self;
    }

    public function setDebug(bool $value)
    {
        $this->debug = $value;
        return $this;
    }

    /**
     * @param $filenameWithExtension
     * @return $this
     * Creates file with the given name
     */
    public function as($filenameWithExtension = 'csv.csv')
    {
        $this->filename = $filenameWithExtension;
        return $this;
    }

    /**
     * @param $chunkSize
     * @return $this
     * @warning Chunks should be less than 10000, Fetching data more than 10000 can use more memory
     */
    public function inChunksOf($chunkSize)
    {
        if ($chunkSize > 1) {
            $this->chunkSize = $chunkSize;
        } elseif ($chunkSize >= 100000) {
            $this->chunkSize = 10000;
        } else {
            $this->chunkSize = 1000;
        }
        return $this;
    }

    /**
     * @param $modelClassOrTableName
     * @param $columnNamesSubstitute
     * @return $this
     */
    public function andWithColumnNamesIn($modelClassOrTableName, $columnNamesSubstitute = [])
    {
        $this->withColumnNamesIn($modelClassOrTableName, $columnNamesSubstitute);
        return $this;
    }

    /**
     * @param $modelClassOrTableName
     * @param $columnNamesSubstitute
     * @return $this
     * @throws \yii\base\InvalidConfigException
     */
    public function withColumnNamesIn($modelClassOrTableName, $columnNamesSubstitute = [])
    {
        if (!empty($columnNamesSubstitute) && is_array($columnNamesSubstitute)) {
            $table_name = null;
            if ($modelClassOrTableName instanceof ActiveRecord) {
                $table_name = $modelClassOrTableName::getTableSchema()->name;
            } elseif (is_string($modelClassOrTableName)) {
                $table_name = (string)$modelClassOrTableName;
            }
            if (!empty($table_name)) {
                foreach ($columnNamesSubstitute as $old_column_name => $new_column_name) {
                    if (substr_count($old_column_name, '@', 0, 1) == 1) {
                        $_virtual_column = substr($old_column_name, 1);
                        $this->column_names_substitute["$table_name.$old_column_name"] = $new_column_name;
                    } elseif (substr_count($old_column_name, '@', 0, 1) === 0 && false !== strpos($old_column_name, '@')) {
                        list($column_name, $alias) = explode('@', $old_column_name);
                        $this->column_names_substitute["$table_name.@$alias"] = $new_column_name;
                    } else {
                        $this->column_names_substitute["$table_name.$old_column_name"] = $new_column_name;
                    }
                }
            }
        }
        return $this;
    }

    /**
     * @param $modelClass
     * @param $columnNamesToRemove
     * @return $this
     * @throws \yii\base\InvalidConfigException
     */
    public function excludingColumnNamesFrom($modelClass, $columnNamesToRemove = [])
    {
        if ($modelClass instanceof ActiveRecord) {
            $table_name = $modelClass::getTableSchema()->name;
            $table_columns = $modelClass::getTableSchema()->getColumnNames();
            if (!empty($columnNamesToRemove)) {
                $this->_excluding_columns[] = [$table_name => $columnNamesToRemove];
                //Remove columns
                $table_columns = array_diff($table_columns, $columnNamesToRemove);
                //Reindex array
                $table_columns = array_values($table_columns);
            }
            if ($this->select_columns == '*' || empty($this->select_columns)) {
                $this->select_columns = "`$table_name`.`" . implode("`,`$table_name`.`", $table_columns) . '`';
            } else {
                $this->select_columns .= ",`$table_name`.`" . implode("`,`$table_name`.`", $table_columns) . '`';
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function select()
    {
        return $this;
    }

    /**
     * @param $modelClassOrTableName
     * @param $ordersOfColumns
     * @return $this
     * @throws \yii\base\InvalidConfigException
     */
    public function setColumnsInOrder($modelClassOrTableName, $ordersOfColumns = [])
    {
        $this->is_column_order_defined = true;
        if (!empty($ordersOfColumns) && is_array($ordersOfColumns)) {
            $table_name = null;
            if ($modelClassOrTableName instanceof ActiveRecord) {
                $table_name = $modelClassOrTableName::getTableSchema()->name;
            } else {
                $table_name = (string)$modelClassOrTableName;
            }
            if (!empty($table_name)) {
                $this->order_of_columns = [["$table_name" => $ordersOfColumns]];
            }
        }
        return $this;
    }

    /**
     * @param $modelClassOrTableName
     * @param $ordersOfColumns
     * @return $this
     * @throws \yii\base\InvalidConfigException
     */
    public function addColumnsInOrder($modelClassOrTableName, $ordersOfColumns = [])
    {
        $this->is_column_order_defined = true;
        if (!empty($ordersOfColumns) && is_array($ordersOfColumns)) {
            $table_name = null;
            if ($modelClassOrTableName instanceof ActiveRecord) {
                $table_name = $modelClassOrTableName::getTableSchema()->name;
            } else {
                $table_name = (string)$modelClassOrTableName;
            }
            if (!empty($table_name)) {
                if (empty($this->order_of_columns)) {
                    $this->order_of_columns = [["$table_name" => $ordersOfColumns]];
                } else {
                    $this->order_of_columns = array_merge($this->order_of_columns, [["$table_name" => (array)$ordersOfColumns]]);
                }
            }
        }
        return $this;
    }

    /**
     * @param $modelClass
     * @param $valuesOfColumnsData
     * @return $this
     */
    public function overrideValuesOf($modelClassOrTableName, $valuesOfColumnsData = [])
    {
        if (!empty($valuesOfColumnsData) && is_array($valuesOfColumnsData)) {
            $table_name = null;
            if ($modelClassOrTableName instanceof ActiveRecord) {
                $table_name = (string)$modelClassOrTableName::getTableSchema()->name;
            } elseif (is_string($modelClassOrTableName)) {
                $table_name = (string)$modelClassOrTableName;
            }
            if (!empty($table_name)) {
                $this->override_column_values = true;
                foreach ($valuesOfColumnsData as $column_name => $new_value) {
                    $this->column_value_substitutes["$table_name.$column_name"] = $new_value;
                }
            }
        }
        return $this;
    }

    public function includeColumnsFrom()
    {
        return $this;
    }

    public function getRawSql()
    {
        if ($this->is_column_order_defined) {
            $this->prepareColumnsInOrder();
        }
        if (substr_count($this->queryOrDataProvider, '*') >= 1) {
            $this->queryOrDataProvider = preg_replace('/SELECT\s*(\*)\s*FROM/', "SELECT $this->select_columns FROM", $this->queryOrDataProvider);
        }
        $this->reGenerateQuery();
        echo $this->queryOrDataProvider;
        return;
    }

    private function prepareColumnsInOrder()
    {
        if (!empty($this->order_of_columns) && $this->is_column_order_defined) {
            $this->select_columns = '*';
            if (is_array($this->order_of_columns)) {
                foreach ($this->order_of_columns as $index => $table_columns) {
                    if (is_array($table_columns)) {
                        $table_name = array_key_first($table_columns);
                        $table_columns = $table_columns[$table_name];
                        if ($this->select_columns == '*' || empty($this->select_columns)) {
                            //TODO: Set virtual column names without order
                            if ($this->override_column_values || $this->is_column_order_defined) {
                                $this->select_columns = $this->setColumnNamesAsColumnAlias($table_name, $table_columns);
                            } else {
                                $this->select_columns = "`$table_name`.`" . implode("`,`$table_name`.`", $table_columns) . '`';
                            }
                        } else {
                            if ($this->override_column_values || $this->is_column_order_defined) {
                                $this->select_columns .= "," . $this->setColumnNamesAsColumnAlias($table_name, $table_columns);
                            } else {
                                $this->select_columns .= ",`$table_name`.`" . implode("`,`$table_name`.`", $table_columns) . '`';
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $table_name
     * @param array $columns
     * @return string
     */
    private function setColumnNamesAsColumnAlias(string $table_name, array $columns)
    {
        $select_string = '';
        if (is_array($columns) && !empty($columns)) {
            foreach ($columns as $index => $column_name) {
                if ($columns[0] !== $column_name) {
                    $select_string .= ",";
                }
                //Check for virtual columns
                if (substr_count($column_name, '@', 0, 1) === 1) {
                    $_virtual_column = substr($column_name, 1);
                    $select_string .= "NULL AS '${table_name}.@${_virtual_column}'";
                } elseif (substr_count($column_name, '@', 0, 1) === 0 && false !== strpos($column_name, '@')) {
                    list($reference_column, $alias) = explode('@', $column_name);
                    $select_string .= "`${table_name}`.`${reference_column}` AS '${table_name}.@${alias}'";
                } else {
                    $select_string .= "`${table_name}`.`${column_name}` AS '${table_name}.${column_name}'";
                }
            }
        }
        return $select_string;
    }

    /**
     * @return void
     */
    private function reGenerateQuery()
    {
        $time_now = time();
        if (false !== strpos($this->queryOrDataProvider, 'LIMIT')) {
            $this->queryOrDataProvider = substr($this->queryOrDataProvider, 0, strpos($this->queryOrDataProvider, 'LIMIT')) . ';';
        }
        $query_limit = (($this->offset > 0) ? "LIMIT {$this->chunkSize} OFFSET {$this->offset}" : " LIMIT {$this->chunkSize}");
        $sql_query = preg_replace('%;\s*$%', '', $this->queryOrDataProvider);
        $local_query = $sql_query . $query_limit;
        $this->queryOrDataProvider = $local_query;
//        if ($this->test_count > 9) {
//            die();
//        }
//        $this->test_count++;
    }

    /**
     * @return void
     * @throws \yii\db\Exception
     */
    public function start($db = null)
    {
        echo "<pre>";
//        header('Content-Encoding: none');
//        header('Cache-Control: max-age=0');
//        header('Content-Transfer-Encoding: binary');
//        header('Content-Disposition: attachment;filename=myfile.xlsx');
        $i = 0; //
        $j = 1;
        $spreadsheet = new Spreadsheet();
//        $spreadsheet->getProperties()
//            ->setCreator("Maarten Balliauw")
//            ->setLastModifiedBy("Maarten Balliauw")
//            ->setTitle("Office 2007 XLSX Test Document")
//            ->setSubject("Office 2007 XLSX Test Document")
//            ->setDescription(
//                "Test document for Office 2007 XLSX, generated using PHP classes."
//            )
//            ->setKeywords("office 2007 openxml php")
//            ->setCategory("Test result file");
        $sheet = $spreadsheet->getActiveSheet();
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setOffice2003Compatibility(true);

        $array = [];
//        ob_start();

        while ($i < 60) {

            $sheet->insertNewRowBefore(1, 1);
            //            $spreadsheet->getActiveSheet()->setCellValueByColumnAndRow($i, 0, 'this');
            $sheet->fromArray(['this', 'is', 'a', 'test i', $i], NULL, 'A1');
            $sheet->insertNewRowBefore(1, 1);
            $sheet->fromArray(['my', 'name', 'is', 'khan', $i], NULL, 'A1');
            ob_end_flush();

            $writer->save('php://output');
            ob_start();
//            $writer->save('php://output');

            if ($i < 59) {
                ob_clean();
            }
            flush();
            ob_flush();
            ++$i;
            $j++;

        }
//        ob_clean();
//        flush();
//        ob_end_clean();
//        $writer->save('php://output');

        die();
        //Prevent headers already sent error
        die();
    }


    //TODO:Prepare headers automatically without data also

    /**
     * @param $url
     * @param $directory
     * @param $name
     * @return array
     */
    private function getFileDetails($url, $directory = null, $name = null)
    {
        $data = [
            'directory' => Yii::$app->getRuntimePath() . DIRECTORY_SEPARATOR . 'student-custom-fee-data',
            'extension' => pathinfo(parse_url($url)['path'], PATHINFO_EXTENSION),
            'name' => 'student-custom-fee-data'
        ];
        if (!empty($directory)) {
            if (!is_dir($data['directory'] . DIRECTORY_SEPARATOR . $directory)
                || !file_exists($data['directory'] . DIRECTORY_SEPARATOR . $directory)) {
                mkdir($data['directory'] . DIRECTORY_SEPARATOR . $directory, 777);
        }
        $data['directory'] = $data['directory'] . DIRECTORY_SEPARATOR . $directory;
    }
    if (!empty($name)) {
        $data['name'] = $name;
    }
    $data['directory'] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $data['directory']);
    $data['path'] = $data['directory'] . DIRECTORY_SEPARATOR . $data['name'] . '.' . $data['extension'];
    return $data;
}

    /**
     * @param $no_cache
     * @return void
     */
    private function downloadHeaders($no_cache = true)
    {
        //Clean previous buffers
        if (headers_sent()) {
            ob_flush();
            flush();
        }
        if (ob_get_level()) {
            ob_get_clean();
        }
        if ($no_cache) {
            // rfc2616 - Section 14.21
            header('Expires: ' . gmdate(DATE_RFC1123));
            // HTTP/1.1
            header(
                'Cache-Control: no-store, no-cache, must-revalidate,'
                . '  pre-check=0, post-check=0, max-age=0'
            );

            header('Pragma: no-cache'); // HTTP/1.0
            header('Last-Modified: ' . gmdate(DATE_RFC1123));
        }
        $this->filename = $filename = Yii::$app->getRuntimePath() . DIRECTORY_SEPARATOR . 'student-custom-fee-data-import' . DIRECTORY_SEPARATOR . 'excel.xlsx';

        $mimetype = 'application/xlsx; charset=UTF-8"';
        header('Content-Encoding: UTF-8'); //Set Character set encoding to UTF-8
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
//        header('Content-Type: text/plain');
        header('Content-Type: force-download');
        //header('Content-Encoding: gzip');
        header('Content-Transfer-Encoding: binary');
        //header('Content-Length: ' . $length);
//        readfile($this->filename);

    }

    /**
     * @param $callback
     * @param $column_value
     * @return mixed|string
     */
    private function setColumnValue($callback, $column_value)
    {
        try {
            if (is_callable($callback)) {
                return call_user_func($callback, $column_value);
            } else {
                return $column_value;
            }
        } catch (\Exception $e) {
            if ($this->debug) {
                return $e->getMessage();
            }
            return '';
        }
    }

    /**
     * @param $array_content
     * @return void
     */
    private function outputBufferHandler($array_content = [], $stream)
    {
        //Clean previous buffers or errors
        if (headers_sent()) {
            flush();
        }
        if (ob_get_level()) {
            ob_get_clean();
        }
        $time_now = time();

        //echo "File Size: " . $this->file_size;
        //Start buffer
        fpassthru($stream);
//        echo file_get_contents($this->filename);
//        echo $this->streamBuffer($array_content);
        flush();
    }

    public function prepareHeaders()
    {
        $local_query = $this->queryOrDataProvider;
    }

    /**
     * @param array $fields
     * @return string
     */
    private function streamBuffer(array $fields): string
    {
        try {
            $buffer = fopen('php://memory', 'wb');
            if ($buffer) {
                //UTF-8 BOM
                fprintf($buffer, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
                //
                if (($length = fputcsv($buffer, $fields, $this->csv_separator, $this->csv_enclosed, $this->csv_escaped)) === false) {
                    if ($this->debug) {
                        return implode(',', array_fill(0, count($fields), "__ERROR_FPUT_CSV_AT__" . __LINE__));
                    }
                    return implode(',', array_fill(0, count($fields), ""));
                }
                //Get file size
                $this->file_size += $length;

                //Point to start line
                rewind($buffer);
                //Get the first line
                $csv_line = stream_get_contents($buffer);
                //Close the buffer
                fclose($buffer);
                $buffer = null;

                //Remove new lines
                //Remove double quotes
                if ($this->csv_removeCRLF) {
                    $csv_line = str_replace(
                        [
                            '"',
                            "\r",
                            "\n",
                        ],
                        '',
                        $csv_line
                    );
                }

                //
                return rtrim($csv_line) . $this->csv_terminated;
            }
        } catch (\Exception $e) {
            if ($this->debug) {
                return "\n***ERROR IN BUFFER***";
            }
            return "\n";
        }
    }
}