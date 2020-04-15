## Использование динамических методов

Используется в крон/административных скриптах.

Требуется инстанциация с передачей PDO-коннекшена к сфинксу и БД:  
```
$mysql_connection = DB::getConnection();
$sphinx_connection = DB::getConnection('SPHINX');

// инстанс
$toolkit = new SphinxToolkit($mysql_connection, $sphinx_connection);

// задаем опции логгирования 
$toolkit->setRebuildIndexOptions([
    'log_rows_inside_chunk' =>  false,
    'log_after_chunk'       =>  false,
    'sleep_after_chunk'     =>  $options['is_sleep'],
    'sleep_time'            =>  $options['sleeptime'],
    'chunk_length'          =>  $options['sql_limit']
]);
```

Перестраиваем индекс:
```
$toolkit->rebuildAbstractIndexMVA('articles', 'rt_Index_Articles', function ($item){
        normalizeSerialData($item['photo']);
        normalizeSerialData($item['rubrics']);
        normalizeSerialData($item['districts']);

        return [
            'id'            =>  $item['id'],
            'type'          =>  1,
            'title'         =>  $item['title'],
            'short'         =>  $item['short'],
            'text'          =>  $item['text_bb'],
            'date_added'    =>  (DateTime::createFromFormat('Y-m-d H:i:s', $item['cdate']))->format('U'),           'photo'         =>  ((@$item['photo']['file'] != "") ? 1 : 0),
            'author'        =>  $item['author'],

            // MVA
            'districts_all' =>  $item['districts_all'],
            'rubrics'       =>  implode(',', array_keys($item['rubrics'])),
            'districts'     =>  implode(',', array_keys($item['districts']))
        ];
    }, "s_hidden = 0 AND s_draft = 0", ['rubrics', 'districts']);

``` 
 
## Использование статических методов

Для корректной работы требуется инициализация.

```
SphinxToolkit::($sphinx_connection_host, $sphinx_connection_port, $options = [])
```

**ВАЖНО**: 
Для всех методов MV-атрибуты в наборах данных нужно передавать как наборы integer-значений. Всегда нужно проверять тип данных. Если это строки - их неоходимо сконвертировать в integer, например при помощи метода `array_map_to_integer` 
```
use function Arris\array_map_to_integer as array_map_to_integer;
``` 


# SUMMARY

- `setRebuildIndexOptions` - установка опций логгирования для динамических методов
- `rebuildAbstractIndexMVA` - полная перестройка абстрактного реалтайм-индекса (с MV-атрибутами)
- `rebuildAbstractIndex` - полная перестройка абстрактного реалтайм-индекса (без MV-атрибутами)
- `createInstance` - cоздает инстанс SphinxQL (для однократного обновления)
- `EmulateBuildExcerpts` - эмуляция создания "сниппетов".

- `init` - инициализация опций логгирования для статического метода полной перестройки абстрактного индекса.
- `rt_RebuildAbstractIndex` - полная перестройка абстрактого РТ-индекса

## Constructor + setRebuildIndexOptions

Опции подключения через MySQLi передаются в конструктор, опции логгирования передаются отдельно.

```
$mysql_connection = DB::getConnection();
$sphinx_connection = DB::getConnection('SPHINX');

// инстанс
$toolkit = new SphinxToolkit($mysql_connection, $sphinx_connection);
```

```
// задаем опции логгирования 
$toolkit->setRebuildIndexOptions([
    'log_rows_inside_chunk' =>  false,
    'log_after_chunk'       =>  false,
    'sleep_after_chunk'     =>  $options['is_sleep'],
    'sleep_time'            =>  $options['sleeptime'],
    'chunk_length'          =>  $options['sql_limit']
]);
```

## rebuildAbstractIndexMVA

Это быстрый, но довольно сложный для рефакторинга метод доступа через PDO. Для обновления MULTI-VALUED атрибутов используется метод превращения их в численные значения.

Используется для полного обновления РТ-индекса с учетом MV-атрибутов; используется анонимная функция, превращающая данные из SQL-базы в данные для вставки в индекс (построчно). 

Требуется инстанциация класса. Для обновления одиночной записи в индексе не используется. 

Сигнатура:
```
rebuildAbstractIndexMVA(string $mysql_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '', array $mva_indexes_list = []):int

 * @param string $mysql_table               -- SQL-таблица исходник
 * @param string $sphinx_index              -- имя индекса (таблицы)
 * @param Closure $make_updateset_method    -- замыкание, анонимная функция, преобразующая исходный набор данных в то, что вставляется в индекс
 * @param string $condition                 -- условие выборки из исходной таблицы (без WHERE !!!)
 * @param array $mva_indexes_list           -- список MVA-индексов, значения которых не нужно биндить через плейсхолдеры
 *
 * @return int - количество обновленных строк в индексе
```

Использование смотри выше.

## EmulateBuildExcerpts

Эмулирует BuildExcerpts из SphinxAPI. Статический метод.

```
* @param $source
* @param $needle
* @param $options: 
* 'before_match' => '<strong>',    // Строка, вставляемая перед ключевым словом. По умолчанию "<strong>".
* 'after_match' => '</strong>',    // Строка, вставляемая после ключевого слова. По умолчанию "</strong>".
* 'chunk_separator' => '...',      // Строка, вставляемая между частями фрагмента. по умолчанию "...".
* опции 'limit', 'around', 'exact_phrase' и 'single_passage' в эмуляции не реализованы

```