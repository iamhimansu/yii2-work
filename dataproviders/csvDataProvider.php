<?php

namespace uims\fee\modules\fee\CsvDataProvider;

use yii\data\BaseDataProvider;

class CsvDataProvider extends BaseDataProvider
{
    /**
     * @var boolean whether header is already present or not
     */
    public $has_header = true;

    /**
     * @var array check for header name
     */
    public $verify_headers = [];
    /**
     * @var string name of the CSV file to read
     */
    public $filename;

    /**
     * @var string|callable name of the key column or a callable returning it
     */
    public $key;

    /**
     * @var SplFileObject
     */
    protected $fileObject; // SplFileObject is very convenient for seeking to particular line in a file

    /**
     * @var array applies filter to the csv
     */
    protected $filterData = [];

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        // open file
        $this->fileObject = new \SplFileObject($this->filename);
        $this->fileObject->setFlags(\SplFileObject::SKIP_EMPTY);
    }

    public function andFilterCsvWhere($array)
    {
        $filter_array = array_filter($array);
        $this->filterData = array_merge($this->filterData, $filter_array);
        return $this;
    }

    public function andFilterCsvWhereLike($array)
    {
        $filter_array = array_filter($array);
        $this->filterData = array_merge($this->filterData, array_filter(['like' => $filter_array]));
        return $this;
    }

    /**
     * @return void
     */
    public function removeFilters()
    {
        $this->filterData = [];
    }

    public function getCsvData()
    {
        $this->removeFilters();
        return $this->getModels();
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareModels()
    {
        $total_filter_count = 0;

        $models = [];
        if (!empty($this->filterData)) {
            $this->pagination = false;
//            $this->pagination = ['limit' => 2];
        }

        $pagination = $this->getPagination();

        if ($this->has_header) {
            $headers = $this->fileObject->fgetcsv();
            //Skip first row
            $this->fileObject->current();
            $this->fileObject->next();
        }
        if ($pagination === false) {
            // in case there's no pagination, read all lines
            while (!$this->fileObject->eof()) {
                $csv_current_line = $this->fileObject->fgetcsv();
                if (!empty($csv_current_line)) {
                    $_model = [];
                    $csv_total_count = count($csv_current_line);
                    if ($this->has_header) {
                        for ($column = 0; $column < $csv_total_count; $column++) {
                            $header_name = $this->removeSpace($this->removeZWNBSP($headers[$column]));
                            $_model[$header_name] = $this->removeZWNBSP($csv_current_line[$column]);
                        }
                        if (!empty($this->filterData)) {
                            if ($this->filterOnCondition($_model)) {
                                $models[] = $_model;
                                $total_filter_count++;
                            } else {
                                continue;
                            }
                        } else {
                            $models[] = $_model;
                        }
                    } else {
                        $models[] = $this->fileObject->fgetcsv();
                    }
                    $this->fileObject->next();
                }
            }
        } else {
            // in case there's pagination, read only a single page
            $pagination->totalCount = $this->getTotalCount();
            $this->fileObject->seek($pagination->getOffset());
            $limit = $pagination->getLimit();

            if ($this->has_header) {
                //Skip first row
                $this->fileObject->current();
                $this->fileObject->next();
            }
            for ($count = 0; $count < $limit; ++$count) {
                $csv_current_line = $this->fileObject->fgetcsv();
                if (!empty($csv_current_line)) {
                    if ($this->has_header) {
                        $_model = [];
                        $csv_total_count = count($csv_current_line);
                        for ($column = 0; $column < $csv_total_count; $column++) {
                            $header_name = $this->removeSpace($this->removeZWNBSP($headers[$column]));
                            $_model[$header_name] = $this->removeZWNBSP($csv_current_line[$column]);
                        }
                        if (!empty($this->filterData)) {
                            if ($this->filterOnCondition($_model)) {
                                $models[] = $_model;
                                $total_filter_count++;
                            } else {
                                continue;
                            }
                        } else {
                            $models[] = $_model;
                        }
                    } else {
                        $models[] = $csv_current_line;
                    }
                    $this->fileObject->next();
                }
            }
        }
        if (!empty($this->filterData)) {
            $this->setTotalCount($total_filter_count);
        }
        return $models;
    }

    protected function removeSpace($content)
    {
        return preg_replace('/\s+/', '', $content);
    }

    protected function removeZWNBSP($content)
    {
        return preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $content);
    }

    /**
     * @param array $model_data
     * @return bool
     */
    protected function filterOnCondition(array $model_data = [])
    {
        $truth_table = [];
        foreach ($this->filterData as $key => $value) {
            //Check for like wise values
            if (is_array($value) && strtoupper($key) === 'LIKE') {
                $like_condition_key = array_key_first($value);
                $like_condition_value = strtolower($value[$like_condition_key]);
                if (!empty($like_condition_value)) {
                    if (isset($model_data[$like_condition_key])) {
                        if (false !== strpos(strtolower($model_data[$like_condition_key]), $like_condition_value)) {
                            $truth_table[] = true;
                        } else {
                            $truth_table[] = false;
                        }
                    }
                }
            } elseif (is_string($value)) {
                //Strictly check for exact values
                if (isset($model_data[$key])) {
                    if ($model_data[$key] === $value) {
                        $truth_table[] = true;
                    } else {
                        $truth_table[] = false;
                    }
                }
            }
        }
        return array_product($truth_table);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareKeys($models)
    {
        if ($this->key !== null) {
            $keys = [];
            foreach ($models as $model) {
                if (is_string($this->key)) {
                    $keys[] = $model[$this->key];
                } else {
                    $keys[] = call_user_func($this->key, $model);
                }
            }

            return $keys;
        }

        return array_keys($models);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareTotalCount()
    {
        $count = 0;

        while (!$this->fileObject->eof()) {
            $this->fileObject->current();
            $this->fileObject->next();
            ++$count;
        }
        return $count;
    }
}
