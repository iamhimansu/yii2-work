<?php

namespace app\models;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yii;
use yii\db\ActiveRecord;
use yii\db\Connection;
use ZipArchive;

class Csv
{
    private const REGEX_LAST_LIMIT_OFFSET = "/(.*)\s+?(LIMIT\s+(?P<limit>\d+)(\s+OFFSET\s+(?P<offset>\d+)\s*)?;)?$/is";
    private static $_queryModel;
    private ?string $_sql;
    private Connection $_db;
    private ?int $_limit = null;
    private ?int $_offset = null;
    private int $_iterate = -1;
    private bool $_canQuery = true;
    private bool $_downloadInChunk = false;
    private string $_fileName = 'export';
    private string $_fileExtension = 'csv';
    private string $_filePath;
    private $_source;
    private int $_startTime;
    private array $_withHeaders = [true];
    private bool $_isHeaderPrepared = false;
    private bool $_streamOutputDirectly = true;

    /**
     * Creates a file forcefully if set to true, by setting permission
     * @var bool
     */
    private bool $_forceCreateFile = false;

    /**
     * Sets the time limit for execution of the script
     * @var int|false|string
     */
    private int $_timeOut;

    /**
     * Row counter
     * @var int
     */
    private int $_rowCount = 0;

    /**
     * Adds serial number at the beginning of the data
     * @var string|false
     */
    private string $_prependSerialNo = '#';
    private int $_inBatchSize;
    private string $_inBatchSuffix;
    private int $_batchSizeCount = 0;
    private int $_count = 0;
    private int $_batchCount = 0;
    private bool $_prepareHtml = false;
    private int $_tablePrepared = 0; // 0 -> table tag not opened, 1 -> table tag opened but not closed, 2 -> table tag opened and closed
    private array $_htmlConfigs = [
        'style' => 'body {
            font-size: 9pt;
            font-family: sans-serif;
        }
        @media print {
            @page {
                margin: 10px;
            }
            body {
                margin: 10px;
            }
        }
        thead {
            background: whitesmoke;
        }
        table,
        tr,
        td,
        th {
            border: 1px solid black;
            border-collapse: collapse;
            white-space: pre;
            font-size: 10pt;
        }
        td,th {
            padding: 5px;
            min-height: 50px !important;
        }',
    ];

    public function __construct()
    {
        $this->_source = fopen('php://stdout', 'w');
        $this->_startTime = time();
        $this->_timeOut = ini_get('max_execution_time');
        $this->_inBatchSize = 0;
        $this->_inBatchSuffix = '_$_';
    }

    public static function query($sql): Csv
    {
        $obj = new static;
        $obj->_sql = $sql;
        return $obj;
    }

    public static function queryBuilder(ActiveRecord $model)
    {
        return Yii::createObject(get_called_class(), [get_class($model)]);
    }

    /**
     * @param $db
     * @return int|null
     */
    public function start($db = null)
    {
        $this->useDatabase($db);
        //Reconfigure query
        $this->processSqlQuery();
        //Run
        return $this->run();
    }

    private function useDatabase($db)
    {
        //Set db
        if (empty($this->_db)) {
            $this->_db = Yii::$app->db;
        }
        if ($db instanceof Connection) {
            $this->_db = $db;
        }

    }

    /**
     * Extracts SQL Query removing limit and offset
     *
     * If the limit is given in raw sql, then it will be used as query limit
     * until chunkSize() is set
     *
     *
     * If the offset is given in the raw sql, then it will be used as query offset
     * until offset() is set
     * @return void
     * @see inChunksOf(), offset()
     *
     */
    private function processSqlQuery()
    {
        $sql = [];
        preg_match(self::REGEX_LAST_LIMIT_OFFSET, $this->_sql, $sql);
        if (isset($sql['limit'])) {
            if (empty($this->_limit)) {
                $this->_limit = intval($sql['limit']);
            }
        } else if (empty($this->_limit)) {
            $this->_limit = 1000;
        }
        //
        if (isset($sql['offset'])) {
            if (empty($this->_offset)) {
                $this->_offset = intval($sql['offset']);
            }
        } else if (empty($this->_offset)) {
            $this->_offset = 0;
        }
        $this->_sql = $sql[1] ?? $this->_sql;
    }

    private function run(): ?int
    {
        //
        if ($this->_iterate === 0) {
            return NULL;
        }
        //override tim limit
        set_time_limit($this->_timeOut);
        //
        try {
            //Set batch size to given batch size
            $this->_batchSizeCount = $this->_inBatchSize;
            //Opens the stream, file or the browser stdin
            $this->openStream();
            //Adds desired headers for downloading file
            $this->setHeaders();
            //Add byte order mark to support hindi names
            $this->setBom($this->_source);

            //
            while ($this->_canQuery) {
                //
                $results = $this->_db->createCommand(
                    $this->regenerateQuery($this->_limit, $this->_offset)
                )->queryAll();

                if (empty($results)) {
                    $this->_canQuery = false;
                }

                if ($this->_iterate > 0) {
                    $this->_iterate--;
                    if ($this->_iterate === 0) {
                        $this->_canQuery = false;
                    }
                }
                $this->_offset += $this->_limit;
                $this->_db->close(); //close db connection
                //Loop and write into csv
                $this->writeHeaders($this->_source, $results);
                $this->writeData($this->_source, $results);
                $this->closeTable($this->_source);
                flush(); //Sena all the data to the user
                unset($results); //Free memory
            }
        } catch (Exception $e) {
            echo "<pre>";
            var_dump($e);
            echo "</pre>";
            die;
        } finally {
            $this->closeStream();
            $folderPath = Yii::$app->getRuntimePath() . DIRECTORY_SEPARATOR . 'klop';  // Specify the path to the folder you want to zip
            $zipFilePath = $folderPath . DIRECTORY_SEPARATOR . 'file.zip';  // Specify the path to the output ZIP file

            // Create recursive directory iterator
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath), RecursiveIteratorIterator::SELF_FIRST);
            $zip = new ZipArchive();
            if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                // Open the output ZIP file for writing
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($folderPath) + 1);

                        $zip->addFile($filePath, $relativePath);

                        // Delete the file
//                        unlink($filePath);
                    }
                }
                // Close the ZIP archive
                $zip->close();
                echo "Folder has been zipped successfully!";
            } else {
                echo "Failed to create ZIP archive.";
            }
        }
        return $this->_count;
    }

    private function openStream()
    {
        if (!$this->_streamOutputDirectly) {
            $filename = $this->_filePath . DIRECTORY_SEPARATOR . $this->_fileName . '.' . $this->_fileExtension;
            $suffix = str_replace("$", $this->_batchCount, $this->_inBatchSuffix);
            $this->_source = fopen(Yii::$app->getRuntimePath() . DIRECTORY_SEPARATOR . 'klop' . DIRECTORY_SEPARATOR . 'export' . $suffix . '.html', 'w+');
        }
    }

    private function setHeaders(bool $noCache = true)
    {
        //Clean previous buffers
        while (ob_get_level()) {
            ob_get_clean();
        }

        if ($noCache) {
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
        $mimetype = 'application/csv; charset=UTF-8';
        if ($this->_prepareHtml) {
            $this->_fileExtension = 'html';
            $mimetype = 'text/html; charset=UTF-8';
        }
        $filename = $this->_fileName . '.' . $this->_fileExtension;

        header('X-DownloadStatus: Initiated');
        header('Content-Encoding: UTF-8'); //Set Character set encoding to UTF-8
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
//        header('Content-Type: text/plain');
        header('Content-Type: ' . $mimetype);
    }

    /**
     * Sets the byte order mark in the given stream
     *
     * Useful to render name in Hindi or in other languages
     * @param $source
     * @return void
     */
    private function setBom($source)
    {
        //UTF-8 BOM
        //Adds Byte Order Mark characters in the buffer
        if (!$this->_streamOutputDirectly) {
            fputs($source, chr(0xEF) . chr(0xBB) . chr(0xBF));
        } else {
            echo chr(0xEF) . chr(0xBB) . chr(0xBF);
        }
    }

    /**
     * This regenerates the query with given limit and offset
     *
     * It takes the current _sql and appends next LIMIT and next OFFSET
     * @param $limit
     * @param $offset
     * @return string
     * @see $_sql, $_limit, $_offset, processSqlQuery()
     */
    private function regenerateQuery($limit, $offset): string
    {

        //We are using array to create new query because string operation will take more time on larger query
        // LIMIT $limit OFFSET $offset;
        $sql = [rtrim($this->_sql, ';')];
        if (!empty($this->_limit)) {
            $sql[] = "LIMIT $limit";
        }
        $sql[] = "OFFSET $offset;";
        return implode(' ', $sql);
    }

    /**
     * Prepares the headers for the csv file
     * @param $source
     * @param $data
     * @return void
     */
    private function writeHeaders($source, $data): void
    {
        $header = [];
        if (!$this->_isHeaderPrepared) {
            if (!empty($data)) {
                if (true === $this->_withHeaders[0]) {
                    $header = array_keys($data[0]);
                }
                $_firstItem = array_splice($this->_withHeaders, 0, 1)[0] ?? null;
                $header = array_merge($header, $this->_withHeaders);
                $this->_withHeaders = array_merge([$_firstItem], $this->_withHeaders);
            }

            if ($this->_streamOutputDirectly) {
                if ($this->_prepareHtml) {
                    echo $this->writeHtmlData($source, $header, true);
                } else {
                    echo implode(',', $header) . PHP_EOL;
                }
            } else {
                if ($this->_prepareHtml) {
                    fwrite($source, $this->writeHtmlData($source, $header, true));
                } else {
                    fputcsv($source, $header);
                }
            }
            $this->_isHeaderPrepared = true;
        }
    }

    /**
     * This generates the table format of the sql query
     *
     * @param $source
     * @param array $data
     * @param bool $isHeader
     * @return string
     */
    private function writeHtmlData($source, array $data, bool $isHeader = false): string
    {

        $html = [];

        if (($this->_canQuery && $this->_tablePrepared === 0) || $this->_rowCount === 0) {
            if (isset($this->_htmlConfigs['style'])) {
                $html[] = "<style>{$this->_htmlConfigs['style']}</style>";
            }
        }

        $this->openTable($source);

        if ($isHeader === true) {
            $html[] = '<thead>';
        }

        $html[] = '<tr>';

        if ($this->_prependSerialNo && $isHeader) {
            $html[] = "<th>{$this->_prependSerialNo}</th>";
        }

        foreach ($data as &$datum) {
            $datum = $isHeader ? "<th>{$datum}</th>" : "<td>{$datum}</td>";
        }

        unset($datum);

        if ($this->_prependSerialNo && !$isHeader) {
            $html[] = "<td>{$this->_rowCount}</td>";
        }
        //
        $html[] = implode('', $data);


        $html[] = '</tr>';

        if ($isHeader === true) {
            $html[] = '</thead>';
        }

        return implode('', $html);
    }

    /**
     * Opens table for html
     * @param $source
     * @return void
     */
    private function openTable($source): void
    {
        $_tableOpen = '<table><tbody>';
        if ($this->_streamOutputDirectly) {
            if ($this->_prepareHtml && $this->_tablePrepared <> 1) {
                echo $_tableOpen;
            }
        } else {
            if ($this->_prepareHtml && $this->_tablePrepared <> 1) {
                fwrite($source, $_tableOpen);
            }
        }
        $this->_tablePrepared = 1;
    }

    private function writeData(&$source, array $data)
    {

        foreach ($data as $datum) {

            ++$this->_rowCount;

            $this->handleDataWriting($source, $datum);

            //Handle batch sizes
            if ($this->_batchSizeCount) {
                //We are decrementing the values, instead of incrementing and then checking it if it is zero [0]
                --$this->_batchSizeCount;
                if ($this->_batchSizeCount <= 0) {
                    $this->_batchSizeCount = $this->_inBatchSize; //Reassign the batch size
                    $this->closeTable($source);
                    $this->closeStream(); // Close the previously opened stream
                    ++$this->_batchCount;//Increment the batch count, i.e : export_1.csv, export_2.csv
                    flush(); // Flush the current output in stream
                    $this->openStream(); //Open a new file to stream
                    $this->_rowCount = 0;
                    $source = $this->_source;
                    $this->_isHeaderPrepared = false; //set headers as false
                    $this->writeHeaders($source, $data);//Rewrite headers in next batch
                }
            }
        }
    }

    private function handleDataWriting($source, $data)
    {
        if ($this->_streamOutputDirectly) {
            if ($this->_prepareHtml) {
                echo $this->writeHtmlData($source, $data);
            } else {
                echo implode(',', $data) . PHP_EOL;
            }
        } else {
            if ($this->_prepareHtml) {
                fwrite($source, $this->writeHtmlData($source, $data));
            } else {
                fputcsv($source, $data);
            }
        }
    }

    /**
     * Closes table
     * @param $source
     * @return void
     */
    private function closeTable($source): void
    {
        $_tableClose = '</tbody></table>';
        if ($this->_streamOutputDirectly && $this->_tablePrepared === 1) {
            if ($this->_prepareHtml) {
                echo $_tableClose;
            }
        } else {
            if ($this->_prepareHtml && $this->_tablePrepared === 1) {
                fwrite($source, $_tableClose);
            }
        }
        $this->_tablePrepared = 2;
    }

    /**
     * Closes the opened stream
     * @return void
     */
    private function closeStream(): void
    {
        if (is_resource($this->_source))
            fclose($this->_source);
    }

    /**
     * If not false|null prepends serial number columns to the data
     *
     * @param string $name
     * @return Csv
     */
    public function serialColumn(string $name): Csv
    {
        $_type = gettype($name);
        if (!empty($name) && $_type === 'string') {
            $this->_prependSerialNo = $name;
        } else {
            $this->_prependSerialNo = false;
        }
        return $this;
    }

    /**
     * Takes bool, (string separated by commas) or array.
     *
     * Defaults to (array) [true]
     *
     * If the header is set to <b>(false or null)</b>, data will be downloaded without any headers
     *
     * If string separated by comma or array is given, the same will be used as headers.
     *
     * @param mixed $headers
     * @return $this
     */
    public function withHeaders($headers = null): Csv
    {
        $_type = gettype($headers);
        if (empty($headers)) {
            $this->_withHeaders = [false];
        } elseif ($_type === 'string') {
            $this->_withHeaders = explode(',', $headers);
        } elseif ($_type === 'array') {
            $this->_withHeaders = $headers;
        }
        return $this;
    }

    /**
     * This adds new headers in the previous headers list.
     *
     * Note: To add values for each row of the header use overrideValuesOf()
     *
     * Takes string separated by comma, or a 1-D array
     *
     * For example:
     *
     * addHeaders('column1,column2'),
     *
     * addHeaders(['column1', 'column2'])
     * @param mixed $headers
     * @return Csv
     * @see overrideValuesOf()
     */
    public function addHeaders($headers = null): Csv
    {
        $_type = gettype($headers);
        if ($_type === 'array') {
            $this->_withHeaders = array_merge($this->_withHeaders, $headers);
        } elseif ($_type === 'string') {
            $this->_withHeaders = array_merge($this->_withHeaders, explode(',', $headers));
        }
        return $this;
    }

    public function overrideValuesOf()
    {
    }

    /**
     * Downloads the file with the given name
     *
     * If force is set to true, the file will be created forcefully, this is helpful for permission issues
     *
     * @param string $filePath
     * @param bool $force
     * @return $this
     * @throws Exception if file is opened in another process or if it is currently inaccessible.
     * @see run()
     */
    public function saveAsFile(string $filePath = '', bool $force = false): Csv
    {
        $this->extracted($filePath, $force);
        return $this;
    }

    /**
     * @param string $fileName
     * @param bool $force
     * @return void
     */
    private function extracted(string $fileName, bool $force)
    {
        if (!empty($fileName)) {
            $fileDetails = $this->getFileDetails($fileName);
            $this->_filePath = $fileDetails['directoryPath'];
            $this->_fileName = $fileDetails['name'];
            $this->_fileExtension = $fileDetails['extension'];
            $this->_streamOutputDirectly = false;
        }
        $this->_forceCreateFile = $force;
    }

    /**
     * This extracts the directory, name and extension of the file
     *
     * @param string $directoryPath
     * @param string $name
     * @return array
     */
    private function getFileDetails(string $directoryPath, string $name = ''): array
    {
        try {
            if (function_exists('pathinfo') && function_exists('parse_url')) {
                $pathData = pathinfo(parse_url($directoryPath, PHP_URL_PATH));
                $extension = $pathData['extension'];
                if (!is_dir($directoryPath) || !file_exists($directoryPath)) {
                    mkdir($directoryPath, 0777, true);
                }
                $name = $name ?: $pathData['filename'];
                $directoryPath = dirname(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directoryPath));
                return compact('directoryPath', 'extension', 'name');
            } else {
                throw new Exception('pathinfo||parse_url methods does not exist.');
            }
        } catch (Exception $e) {
            $extension = 'csv';
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directoryPath);
            $directoryPath = dirname($normalizedPath);
            $explodedPath = explode(DIRECTORY_SEPARATOR, $normalizedPath);
            $fullFileName = array_pop($explodedPath);

            $fullFileDetails = explode('.', $fullFileName);
            $name = $fullFileDetails[0] ?? ('export-' . time());

            if ($_extension = end($fullFileDetails)) {
                $extension = $_extension;
            }
            return compact('directoryPath', 'extension', 'name');
        }
    }

    /**
     * This download the file into batches and zips the downloaded file
     *
     * This will download the file in chunks containing records given in $size with the given $suffix
     *
     * For example:
     *
     * $batchSize = 1000, $suffix = 'data_file_$_'
     *
     * This will create csv file split with 1000 records, till last record and named
     *
     * {name_of_your_file}_data_file_1
     *
     * {name_of_your_file}_data_file_2
     *
     * {name_of_your_file}_data_file_nth
     *
     *
     * @param string $zipName
     * @param string|null $suffix
     * @param int $batchSize
     * @param bool $forceCreateFile
     * @return $this
     */
    public function saveAsBatches(string $zipName, ?string $suffix = '_$', int $batchSize = 0, bool $forceCreateFile = false): Csv
    {
        if (!empty($suffix)) {
            $this->_inBatchSuffix = $suffix;
        }
        if (!empty($batchSize)) {
            $this->_inBatchSize = $batchSize;
        }
        $this->extracted($zipName, $forceCreateFile);
        return $this;
    }

    /**
     * Sets the time limit for server, start time from 0
     *
     * If set to zero, no time limit is imposed.
     *
     * Check php.ini file for getting default time out
     *
     * @param int $seconds
     * @return $this
     * @see https://www.php.net/manual/en/function.set-time-limit.php
     */
    public function timeOut(int $seconds = 0): Csv
    {
        if (!empty($seconds)) {
            $this->_timeOut = $seconds;
        }
        return $this;
    }

    /**
     * Downloads the file directly to the clients browser with the given name
     *
     * Note: the server timeout may interrupt the download
     * @param string $fileName
     * @return $this
     * @see timeOut()
     */
    public function saveAs(string $fileName = ''): Csv
    {
        if (!empty($fileName)) {
            $this->_fileName = $fileName;
        }
        return $this;
    }

    /**
     * Dumps the sql data into table in HTML format
     *
     *
     * @param array $configs
     * @param bool $html
     * @return $this
     */
    public function html(array $configs = [], bool $html = true): Csv
    {
        if (!empty($configs)) {
            $this->_htmlConfigs = $configs;
        }
        $this->_prepareHtml = $html;
        return $this;
    }

    /**
     * This limits the no. of executions of sql queries, defaults to -1
     *
     * For example:
     *
     * $limit = 0 will return null
     *
     * $limit = 1 will execute the query only once
     *
     * $limit = -1 will execute the query till the last record from database
     *
     * @param int $limit
     * @return $this
     */
    public function iterate(int $limit): Csv
    {
        $this->_iterate = $limit;
        return $this;
    }

    //TODO:  UTNA HI LIMIT ASSIGN KAR SAKTA HAI JITNA SERVER EK BAAR MEIN DATA LAA SAKTA HAI AUR ARRAY MEIN STORE KAR SAKTA HAI

    /**
     * Sets the limit of data to be fetched in generated query.
     *
     * **Note: this will override the limit present in the sql query
     *
     * @param int $limit
     * @return $this
     * @see processSqlQuery()
     */
    public function inChunksOf(int $limit): Csv
    {
        if ($limit) {
            $this->_limit = $limit;
        }
        return $this;
    }

    /**
     * Sets the limit of data to be fetched in generated query
     *
     * ** Note: this will override the offset present in the sql query
     *
     * @param $offset
     * @return $this
     * @see processSqlQuery()
     */
    public function offset($offset): Csv
    {
        if (is_int($offset) && $offset > 0) {
            $this->_offset = $offset;
        }
        return $this;
    }

    /**
     * @param null $db
     * @return int
     */
    public function getCount($db = null): int
    {
        $this->useDatabase($db);
        $this->processSqlQuery();
        return $this->findTotalRowsCount();
    }

    /**
     * Wraps the query with new derived table and returns the count
     */
    private function findTotalRowsCount()
    {
        if (!empty($this->_sql)) {
            try {
                $sql = "SELECT COUNT(*) as `row_count` FROM (" . rtrim($this->_sql, ';') . ") AS DS1";
                return $this->_count = $this->_db->createCommand($sql)->queryScalar();
            } catch (Exception $e) {
                echo "<pre>";
                var_dump($e->getMessage());
                echo "</pre>";
                die;
            }
        }
    }
}
