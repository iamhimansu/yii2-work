# large-data-export-csv
Exports large data in csv, based on YII2

# Needs refactoring

## uses:
```php
    $export = new Csv();
    return $export
                  ->as('data.csv')
                  ->inChunksOf(1000)
                  ->start();
    
    
