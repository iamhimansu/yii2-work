     $i = 0;
        $reader = IOFactory::createReaderForFile($this->file);
        $htmlReader = new \PhpOffice\PhpSpreadsheet\Reader\Html();
        $reader->setReadDataOnly(true);

        $time = microtime(1);
        $filesize = filesize($this->file);

        $fp = fopen($this->file, "rb");
        while (!feof($fp)) {
            if (ftell($fp) >= $filesize) {
                fclose($fp);
                break;
            }
            $data = fread($fp, 1 << 20);
            //
            $sp = $htmlReader->loadFromString($data);


            echo "<pre>";
            var_dump($sp);
            echo "</pre>";
            die;


            fseek($fp, ftell($fp));
            $i++;
        }
        echo "<pre>";
        var_dump(microtime(1) - $time);
        echo "</pre>";
        die;
        die;
