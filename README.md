# large-data-export-csv
Exports large data in csv, based on YII2

# Needs refactoring

## uses:
```php
    $export = Csv::export("SELECT * FROM xyz");
    return $export
                  ->as('data.csv')
                  ->inChunksOf(1000)
                  ->start();
    
    
