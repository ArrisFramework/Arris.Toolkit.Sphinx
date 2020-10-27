# getDataSetFromSphinx

Использовалось в SteamboatEngine/functions.php в версии 1.26.1 (latest implementation), предполагался перенос в SphinxToolkit, 
но не сделан (т.к. есть зависимость от AppLogger)

Как временное решение, функция задается индивидуально для каждого проекта.

```php
/**
 * Загружает список айдишников из сфинкс-индекса по переданному запросу.
 *
 * Old implementation is `\SteamBoat\SBSearch::get_IDs_DataSet`
 *
 * @param string $search_query      - строка запроса
 * @param string $source_index      - имя индекса
 * @param string $sort_field        - поле сортировки
 * @param string $sort_order        - условие сортировки
 * @param int $limit                - количество
 * @param array $option_weight      - опции "веса"
 * @return array                    - список айдишников
 */
function getDataSetFromSphinx(string $search_query, string $source_index, string $sort_field, string $sort_order = 'DESC', int $limit = 5, array $option_weight = []): array
    {
        $found_dataset = [];
        $compiled_request = '';
        if (empty($source_index)) return $found_dataset;
        try {
            $search_request = \Arris\Toolkit\SphinxToolkit::createInstance()
                ->select()
                ->from($source_index);
            if (!empty($sort_field)) {
                $search_request = $search_request
                    ->orderBy($sort_field, $sort_order);
            }
            if (!empty($option_weight)) {
                $search_request = $search_request
                    ->option('field_weights', $option_weight);
            }
            if (!is_null($limit) && is_numeric($limit)) {
                $search_request = $search_request
                    ->limit($limit);
            }
            if (strlen($search_query) > 0) {
                $search_request = $search_request
                    ->match(['title'], $search_query);
            }
            $search_result = $search_request->execute();
            while ($row = $search_result->fetchAssoc()) {
                $found_dataset[] = $row['id'];
            }
        } catch (Exception $e) {
            \Arris\AppLogger::scope('sphinx')->error(
                __CLASS__ . '/' . __METHOD__ .
                " Error fetching data from `{$source_index}` : " . $e->getMessage(),
                [
                    htmlspecialchars(urldecode($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])),
                    $search_request->getCompiled(),
                    $e->getCode()
                ]
            );
        }
        return $found_dataset;
    } // get_IDs_DataSet()
```