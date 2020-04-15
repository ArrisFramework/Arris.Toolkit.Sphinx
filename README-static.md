## init

Инициализация статического интерфейса к методам

## rt_ReplaceIndex 

Обновляет (заменяет) одну строчку реалтайм-индекса по набору данных. 

Важно: набор данных должен содержать ID строки в индексе (в РТ-индексе нет понятия "автоинкремента" строк)

```
rt_ReplaceIndex(string $index_name, array $updateset)

/**
* Обновляет (REPLACE) реалтайм-индекс по набору данных
* с созданием коннекшена "сейчас"
*
* @param string $index_name
* @param array $updateset
* @return ResultSetInterface|null
*
* @throws DatabaseException
* @throws \Foolz\SphinxQL\Exception\ConnectionException
* @throws \Foolz\SphinxQL\Exception\SphinxQLException
*/
```

Пример использования:
```
$dataset = [
    'id'            =>  $id,
    'title'         =>  $item['title'],
    'short'         =>  $item['short'],
    'text'          =>  $item['text_bb'],

    'date_added'    =>  date_format( date_create_from_format('Y-m-d H:i:s', $item['cdate']), 'U' ),

    'type'          =>  1,
    'photo'         =>  empty($article['photo']['file']) ? 0 : 1,
    'author'        =>  $item['author'],

    // @todo: REQURED SphinxDynoConf :: MVA Attributes in RT_ARTICLES index
    'districts_all' =>  $item['districts_all'],
    'rubrics'       =>  array_keys($item['rubrics']),
    'districts'     =>  array_keys($item['districts'])
];
rt_ReplaceIndex('rt_articles', $dataset);
```

## rt_DeleteIndex

Удаляет строку из индекса 

```
rt_DeleteIndex(string $index_name, string $field, $field_value = null)

/**
 * Удаляет строку реалтайм-индекса
 * с созданием коннекшена "сейчас"
 *
 * @param string $index_name        -- индекс
 * @param string $field             -- поле для поиска индекса
 * @param null $field_value         -- значение для поиска индекса
 * @return ResultSetInterface|null
 *
 * @throws DatabaseException
 * @throws \Foolz\SphinxQL\Exception\ConnectionException
 * @throws \Foolz\SphinxQL\Exception\SphinxQLException
 */
```
Пример использования:
```
rt_DeleteIndex('rt_articles', 'id', $id);
```

## rt_RebuildAbstractIndex

Логический аналог метода `rebuildAbstractIndexMVA`, только статический, с MV-атрибутами и через библиотеку Foolz\SQL

```
rt_RebuildAbstractIndex(\PDO $pdo_connection, string $sql_source_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '')

 /**
 * @param \PDO $pdo_connection
 * @param string $sql_source_table
 * @param string $sphinx_index
 * @param Closure $make_updateset_method
 * @param string $condition
 * @return int
 * @throws DatabaseException
 * @throws \Foolz\SphinxQL\Exception\ConnectionException
 * @throws \Foolz\SphinxQL\Exception\SphinxQLException
 */
```

## createInstance 

Создает инстанс SphinxQL (для однократного обновления)

```
Arris\Toolkit\SphinxToolkit::createInstance()->...
```

