<?php
namespace samarth\ignou2022july\modules\admadmin\Export;

use Yii;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Connection;
use yii\helpers\Inflector;

class Csv extends ActiveQuery
{
    //Configs
    public $csv_columns = false;
    //CSV Configs
    public $csv_structure_or_data = 'data';
    public $csv_null = 'NULL';
    public $csv_separator = ',';
    public $csv_enclosed = '"';
    public $csv_escaped = '"';
    public $csv_terminated = PHP_EOL;
    public $csv_removeCRLF = true;
    public $modelClass = null;
    protected $fetch_next_batch = false;

    //File Configuration
    protected $debug = false;
    protected $filename = '';
    protected $file_details;
    //
    protected $file_size = 0;
    protected $valid_extensions = ['csv'];
    protected $queryOrDataProvider;
    protected $chunkSize = 0;
    protected $csv_offset = 0;

    //Columns manipulation
    protected $_start_time;
    protected $column_names_substitute = [];
    protected $column_value_substitutes = [];
    protected $override_column_values = false;
    protected $columns = [];
    protected $select_columns = '*';
    protected $db = null;
    protected $order_of_columns = [];
    protected $is_column_order_defined = false;
    protected $_excluding_columns = [];
    protected $test_count = 0;
    protected $using_query_builder = false;
    //Header is prepared
    protected $run_once = false;
    protected $header_is_prepared = false;
    protected $totalCount = 0;

    /**
     * @param $modelClass
     * @param $config
     */
    public function __construct($modelClass = null, $config = [])
    {
        if (!empty($modelClass)) {
            $this->modelClass = $modelClass::className();
        }
        parent::__construct($config);
    }

    /**
     * @param $queryOrDataProvider
     * @return Data
     */
    public static function export($queryOrDataProvider)
    {
        $_self = new self();
        $_self->queryOrDataProvider = $queryOrDataProvider;
        if ($queryOrDataProvider instanceof ActiveDataProvider) {
            $_self->queryOrDataProvider = $queryOrDataProvider->query->createCommand()->getRawSql();
        }
        return $_self;
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
        return $this->queryOrDataProvider;
    }

    protected function prepareColumnsInOrder()
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
    protected function setColumnNamesAsColumnAlias(string $table_name, array $columns)
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
    protected function reGenerateQuery()
    {
        if (!$this->run_once) {
            $time_now = time();
//            if (false !== strpos($this->queryOrDataProvider, 'LIMIT')) {
            //Regex to find everything berfore last LIMIT
            $regex = '/^([^][]+(?:(?=LIMIT\s+\d+(\s+OFFSET\s+\d+\s*)?;)))/mi';
            //find Everything before limit
            $re = false;
            //remove last semicolon
            $this->queryOrDataProvider = rtrim($this->queryOrDataProvider, ";");
            $this->queryOrDataProvider .= ';';
            if ($re = preg_match($regex, $this->queryOrDataProvider, $queryPart)) {
                $this->queryOrDataProvider = $queryPart[0];

            }//Not to be trusted else part
//                else {
//                    $this->queryOrDataProvider = substr($this->queryOrDataProvider, 0, strpos($this->queryOrDataProvider, 'LIMIT')) . ';';
//                }
//            }

            $query_limit = (($this->csv_offset > 0) ? " LIMIT {$this->chunkSize} OFFSET {$this->csv_offset}" : " LIMIT {$this->chunkSize}");
            $sql_query = preg_replace('%;\s*$%', '', $this->queryOrDataProvider);
            $local_query = $sql_query . $query_limit;
            $this->queryOrDataProvider = $local_query;

//            echo "<pre>";
//            print_r($this->queryOrDataProvider);
//            die;
        }
    }

    /**
     * @param $modelClass
     * @param $config
     * @return object
     * @throws \yii\base\InvalidConfigException
     */
    public static function queryWith($modelClass)
    {
        $_self = new self();
        $_self->modelClass = $modelClass::className();
        $_self->using_query_builder = true;
        return $_self;
    }

    /**
     * @override method
     * Overiding default all method as we are using this to create custom query
     * @param $db
     * @return $this|array|ActiveRecord[]
     */
    public function all($db = null)
    {
        return $this;
    }

    public function setDebug(bool $value)
    {
        $this->debug = $value;
        return $this;
    }

    /**
     * Creates file with the given name
     * @param $filenameWithExtension
     * @return $this
     */
    public function as($filenameWithExtension = 'data.csv')
    {
        $this->filename = $filenameWithExtension;
        return $this;
    }

    /**
     * Chunks should be less than 10000, Fetching data more than 10000 can use more memory
     * @param $chunkSize
     * @return $this
     */
    public function inChunksOf($chunkSize)
    {
        if ($chunkSize > 1 && $chunkSize < 10000) {
            $this->chunkSize = $chunkSize;
        } elseif ($chunkSize >= 10000) {
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

    /**
     * @return void
     * @throws \yii\db\Exception
     */

    public function start($db = null)
    {

        if ($this->is_column_order_defined) {
            $this->prepareColumnsInOrder();
        }
        //start time
        $this->_start_time = time();

        if (!empty($db)) {
            if ($db instanceof Connection) {
                $this->db = $db;
            }
        } else {
            $this->db = Yii::$app->db;
        }

        //Assign file details
        $this->file_details = $this->getFileDetails($this->filename, null, $this->filename);
        //Run Query
        $csv_offset = $this->csv_offset;
        $limit = $this->chunkSize;

        if ($this->using_query_builder) {
            $this->queryOrDataProvider = $this->createCommand($this->db)->getRawSql();
        }
        if (substr_count($this->queryOrDataProvider, '*') >= 1) {
            $this->queryOrDataProvider = preg_replace('/SELECT\s*(\*)\s*FROM/', "SELECT $this->select_columns FROM", $this->queryOrDataProvider);
        }
        try {
            //Create query
            $this->reGenerateQuery();
            $rows = $this->db->createCommand($this->queryOrDataProvider)->queryAll();
            if (empty($rows)) {
                return;
            }
            //If everything is fine download the file
            //Download header
            $this->downloadHeaders();
            $this->fetch_next_batch = true;
            while (!empty($rows) && $this->fetch_next_batch) {
//                echo "\n";
//                echo $this->$this->csv_offset;
                $this->fetch_next_batch = false;
                $total_rows_count = count($rows);
                for ($i = 0; $i < $total_rows_count; $i++) { // outside row data
                    $_temp = [];
                    $headers = [];
                    foreach ($rows[$i] as $field_name => $field_value) {
                        if (isset($this->column_value_substitutes[$field_name])) {
                            //Check if closure
                            if ($this->column_value_substitutes[$field_name] instanceof \Closure) {
                                $field_value = $this->setColumnValue($this->column_value_substitutes[$field_name], $field_value);
                            } else {
                                $field_value = $this->column_value_substitutes[$field_name];
                            }
                        }
                        if (!$this->header_is_prepared) {
                            //Check withColumnNames
                            if (!empty($this->column_names_substitute)) {
                                $headers = array_merge($headers, [$this->enclose(trim($this->column_names_substitute[$field_name] ??
                                    (false !== strpos($field_name, '.') ?
                                        Inflector::titleize(substr($field_name, strpos($field_name, '.') + 1)) :
                                        Inflector::titleize($field_name))))]);
                            } else {
                                $headers = array_merge($headers, [$this->enclose($field_name)]);
                            }
                        }
                        //Create temporary array
                        //Because currently we will use php's inbuilt function (fputcsv) to write csv
                        $_temp[] = $this->enclose($field_value);
                    }
                    if (!$this->header_is_prepared) {
                        $this->outputBufferHandler($headers);
                        $headers = null;
                        $this->header_is_prepared = true;
                    }
                    //Send Output buffer
                    $this->outputBufferHandler($_temp);
                    //
                    if ($i >= ($total_rows_count - 1)) {
                        if ($this->run_once) {
                            $rows = [];
                            $total_rows_count = 0;
                            continue;
                        }
                        $this->csv_offset += $this->chunkSize - 1;
                        $csv_offset = $this->csv_offset;
                        $limit = $this->chunkSize;
                        //TODO: Need to check these
                        //Check if current offset +1 i.e next row exists
                        $old_offset = $this->csv_offset;
                        $old_chunk_size = $this->chunkSize;

                        $this->chunkSize = 1;
                        $this->csv_offset += 1;

                        $this->reGenerateQuery();
                        $next_record = $this->db->createCommand($this->queryOrDataProvider)->queryAll();
                        if (!empty($next_record)) {
                            $this->chunkSize = $old_chunk_size;
                            $this->csv_offset = $old_offset;
                        } else {
                            $rows = [];
                            continue;
                        }
                        //TODO: Check above code
                        //Recreate Query
                        $this->fetch_next_batch = true;
                        $this->reGenerateQuery();
                        $rows = $this->db->createCommand($this->queryOrDataProvider)->queryAll();
                        $total_rows_count = count($rows);
                        $i = 0;
                    }
                }
            }
        } catch (\Exception $e) {
            $php_errormsg = "ERROR: An Error occured while generating the file";
            if ($this->debug) {
                $php_errormsg = '<pre><h3><b style="color: #ff4430">ERROR: An Error occured while generating the file</b></h3>';
                $php_errormsg .= '<br><b style="padding-left: 10px">The SQL query created was:</b>';
                $php_errormsg .= '<br><b style="padding-left: 20px">' . $this->queryOrDataProvider . '</b></pre>';
            }
            echo $php_errormsg;
            return $php_errormsg;
        }
        //Prevent headers already sent error
        if (headers_sent()) {
            die;
        }
    }


    //TODO:Prepare headers automatically without data also

    /**
     * @param $url
     * @param $directory
     * @param $name
     * @return array
     */
    protected function getFileDetails($url, $directory = null, $name = null)
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
    protected function downloadHeaders($no_cache = true)
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
        //
        $filename = $this->filename;

        $mimetype = 'application/csv; charset=UTF-8"';
        header('X-DownloadStatus: Intiated');
        header('Content-Encoding: UTF-8'); //Set Character set encoding to UTF-8
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        //header('Content-Type: text/plain');
        header('Content-Type: ' . $mimetype);
        //header('Content-Encoding: gzip');
        header('Content-Transfer-Encoding: binary');
        //header('Content-Length: ' . $data_size);

        //UTF-8 BOM
        //Adds Byte Order mark characters in the buffer
        echo $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF));
    }

    public function getApproxTableData($db, $table_name)
    {

        $table_data = [];
        $table_data['Name'] = '';
        $table_data['Engine'] = '';
        $table_data['Version'] = '';
        $table_data['Row_format'] = '';
        $table_data['Rows'] = '';
        $table_data['Avg_row_length'] = '';
        $table_data['Data_length'] = '';
        $table_data['Max_data_length'] = '';
        $table_data['Index_length'] = '';
        $table_data['Data_free'] = '';
        $table_data['Auto_increment'] = '';
        $table_data['Create_time'] = '';
        $table_data['Update_time'] = '';
        $table_data['Check_time'] = '';
        $table_data['Collation'] = '';
        $table_data['Checksum'] = '';
        $table_data['Create_options'] = '';
        $table_data['Create_options'] = '';
        $table_data['Comment'] = '';
        try {
            $table_data = $db->createCommand("SHOW TABLE STATUS WHERE NAME =" . $table_name)->queryOne();
            return $table_data;
        } catch (\Exception $e) {
            return $table_data;
        }
    }

    /**
     * @param $callback
     * @param $column_value
     * @return mixed|string
     */
    protected function setColumnValue($callback, $column_value)
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
     * This encloses the content inside single quote if it has spaces or comma (,)
     * @param $content
     * @return array|string|string[]|null
     */
    protected function enclose($content)
    {
        $value = trim($content);
        if (false !== strpos($content, $this->csv_separator)
            || false !== strpos($content, ";")
            || false !== strpos($content, " ")) {
            $value = '"' . trim($content) . '"';
        }
        return $this->removeZWNBSP($value);
    }

    /**
     * This removes zero width no breaking spaces if present in the string
     * @param $content
     * @return array|string|string[]|null
     */
    protected function removeZWNBSP($content)
    {
        return preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $content);
    }

    /**
     * @param $array_content
     * @return void
     */
    protected function outputBufferHandler($array_content)
    {
        //Clean previous buffers or errors
        if (headers_sent()) {
            flush();
        }
        if (ob_get_level()) {
            ob_get_clean();
        }
        $time_now = time();

        //Start buffer
        echo $this->streamBuffer($array_content);
        flush();
    }

    /**
     * @param array $fields
     * @return string
     */
    protected function streamBuffer(array $fields): string
    {
        try {
            $csv_line = implode($this->csv_separator, $fields);

            //Remove new lines
            if ($this->csv_removeCRLF) {
                $csv_line = str_replace(
                    [
                        "\r",
                        "\n",
                    ],
                    "",
                    $csv_line
                );
            }
            //Get file size
            $this->file_size += strlen($csv_line);

            //return csv line
            return $csv_line . $this->csv_terminated;
            //
        } catch (\Exception $e) {
            if ($this->debug) {
                return "\n***ERROR IN BUFFER***";
            }
            return "\n";
        }
    }

    public function runOnce(bool $bool)
    {
        $this->run_once = $bool;
        return $this;
    }

    public function prepareHeaders()
    {
        $local_query = $this->queryOrDataProvider;
    }

    public function columns()
    {
        return $this;
    }
}