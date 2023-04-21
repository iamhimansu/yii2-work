<?php

class Merge {
    public function mergeConfigsDifference(&$config, $bluePrintArray)
    {
        foreach ($bluePrintArray as $key => $value) {
            if (is_array($value) && isset($config[$key]) && is_array($config[$key])) {
                $this->mergeConfigsDifference($config[$key], $value);
            } else if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }
    }
}

$arr1 = [
    "name" => "Himanshu",
    "data" => [
      "id" => 1
    ]
  ];

$arr2 = [
    "name" => "Raj",
    "data" => [
      "id" => 1
    ]
  ];
$m = new Merge();

echo "<pre>";
var_dump($m->mergeConfigsDifference($arr1, $arr2));
echo "</pre>";



// The above code is equal to PHP inbuilt function array_replace_recursive();


echo "<pre>";
var_dump(array_replace_recursive($arr1, $arr2));
echo "</pre>";


?>
