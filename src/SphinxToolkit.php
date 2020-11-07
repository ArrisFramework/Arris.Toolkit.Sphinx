<?php

namespace Arris\Toolkit;

use Closure;
use Exception;
use Foolz\SphinxQL\Drivers\ConnectionInterface;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use PDO;
use PDOException;
use Arris\Toolkit\CLIConsole;

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Drivers\ResultSetInterface;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Helper;
use Foolz\SphinxQL\SphinxQL;
use Psr\Log\LoggerInterface;

class SphinxToolkit implements SphinxToolkitMysqliInterface, SphinxToolkitFoolzInterface
{
    use SphinxToolkitHelper;
    
    /* =========================== DYNAMIC IMPLEMENTATION ================================ */
    /**
     * @var array
     */
    private $rai_options;

    /**
     * @var PDO
     */
    private $mysql_connection;

    /**
     * @var PDO
     */
    private $sphinx_connection;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(PDO $mysql_connection, PDO $sphinx_connection, LoggerInterface $logger = null)
    {
        $this->mysql_connection = $mysql_connection;
        $this->sphinx_connection = $sphinx_connection;
        $this->logger = $logger;
    }

    public function setRebuildIndexOptions(array $options = []):array
    {
        // разворачиваем опции с установкой дефолтов
        $this->rai_options['chunk_length'] = SphinxToolkitHelper::setOption($options, 'chunk_length', 500);

        $this->rai_options['log_rows_inside_chunk'] = SphinxToolkitHelper::setOption($options, 'log_rows_inside_chunk', true);
        $this->rai_options['log_total_rows_found'] = SphinxToolkitHelper::setOption($options, 'log_total_rows_found', true);

        $this->rai_options['log_before_chunk'] = SphinxToolkitHelper::setOption($options, 'log_before_chunk', true);
        $this->rai_options['log_after_chunk'] = SphinxToolkitHelper::setOption($options, 'log_after_chunk', true);

        $this->rai_options['sleep_after_chunk'] = SphinxToolkitHelper::setOption($options, 'sleep_after_chunk', true);

        $this->rai_options['sleep_time'] = SphinxToolkitHelper::setOption($options, 'sleep_time', 1);
        if ($this->rai_options['sleep_time'] == 0) {
            $this->rai_options['sleep_after_chunk'] = false;
        }

        $this->rai_options['log_before_index'] = SphinxToolkitHelper::setOption($options, 'log_before_index', true);
        $this->rai_options['log_after_index'] = SphinxToolkitHelper::setOption($options, 'log_after_index', true);

        return $this->rai_options;
    } // setRebuildIndexOptions


    public function rebuildAbstractIndex(string $mysql_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '', bool $use_mva = false, array $mva_indexes_list = []):int
    {
        $mysql_connection = $this->mysql_connection;
        $sphinx_connection = $this->sphinx_connection;

        // проверяем, существует ли индекс
        if (! SphinxToolkitHelper::RTIndexCheckExist($this->sphinx_connection, $sphinx_index))
            throw new Exception("`{$sphinx_index}` not present", 1);

        $chunk_size = $this->rai_options['chunk_length'];

        // truncate
        SphinxToolkitHelper::RTIndexTruncate($sphinx_connection, $sphinx_index);

        // get total count
        $total_count = SphinxToolkitHelper::MySQL_GetRowsCount($mysql_connection, $mysql_table, $condition);
        $total_updated = 0;

        if ($this->rai_options['log_before_index'])
            CLIConsole::say("<font color='yellow'>[{$sphinx_index}]</font> index : ", false);

        if ($this->rai_options['log_total_rows_found'])
            CLIConsole::say("<font color='green'>{$total_count}</font> elements found for rebuild.");

        // iterate chunks
        for ($i = 0; $i < ceil($total_count / $chunk_size); $i++) {
            $offset = $i * $chunk_size;

            if ($this->rai_options['log_before_chunk'])
                CLIConsole::say("Rebuilding elements from <font color='green'>{$offset}</font>, <font color='yellow'>{$chunk_size}</font> count... " , false);

            $query_chunk_data = "SELECT * FROM {$mysql_table} ";
            $query_chunk_data.= $condition != '' ? " WHERE {$condition} " : '';
            $query_chunk_data.= "ORDER BY id DESC LIMIT {$offset}, {$chunk_size} ";

            $sth = $mysql_connection->query($query_chunk_data);

            // iterate inside chunk
            while ($item = $sth->fetch()) {
                if ($this->rai_options['log_rows_inside_chunk'])
                    CLIConsole::say("{$mysql_table}: {$item['id']}");

                $update_set = $make_updateset_method($item);

                if ($use_mva) {
                    list($update_query, $update_set) = self::buildReplaceQueryMVA($sphinx_index, $update_set, $mva_indexes_list);
                } else {
                    $update_query = self::buildReplaceQuery($sphinx_index, $update_set);
                }

                $update_statement = $sphinx_connection->prepare($update_query);
                $update_statement->execute($update_set);
                $total_updated++;
            } // while

            $breakline_after_chunk = !$this->rai_options['sleep_after_chunk'];

            if ($this->rai_options['log_after_chunk']) {
                CLIConsole::say("Updated RT-index <font color='yellow'>{$sphinx_index}</font>.", $breakline_after_chunk);
            } else {
                CLIConsole::say("<strong>Ok</strong>", $breakline_after_chunk);
            }

            if ($this->rai_options['sleep_after_chunk']) {
                CLIConsole::say("ZZZZzzz for {$this->rai_options['sleep_time']} second(s)... ", FALSE);
                sleep($this->rai_options['sleep_time']);
                CLIConsole::say("I woke up!");
            }
        } // for
        if ($this->rai_options['log_after_index'])
            CLIConsole::say("Total updated <strong>{$total_updated}</strong> elements for <font color='yellow'>{$sphinx_index}</font> RT-index. <br>");

        return $total_updated;
    } // rebuildAbstractIndex


    public function rebuildAbstractIndexMVA(string $mysql_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '', array $mva_indexes_list = []):int
    {
        $mysql_connection = $this->mysql_connection;
        $sphinx_connection = $this->sphinx_connection;

        $chunk_size = $this->rai_options['chunk_length'];

        // проверяем, существует ли индекс
        if (! SphinxToolkitHelper::RTIndexCheckExist($this->sphinx_connection, $sphinx_index))
            throw new Exception("`{$sphinx_index}` not present", 1);

        // truncate
        SphinxToolkitHelper::RTIndexTruncate($sphinx_connection, $sphinx_index);

        // get total count
        $total_count = SphinxToolkitHelper::MySQL_GetRowsCount($mysql_connection, $mysql_table, $condition);
        $total_updated = 0;

        if ($this->rai_options['log_before_index'])
            CLIConsole::say("<font color='yellow'>[{$sphinx_index}]</font> index : ", false);

        if ($this->rai_options['log_total_rows_found'])
            CLIConsole::say("<font color='green'>{$total_count}</font> elements found for rebuild.");

        // iterate chunks
        for ($i = 0; $i < ceil($total_count / $chunk_size); $i++) {
            $offset = $i * $chunk_size;

            if ($this->rai_options['log_before_chunk'])
                CLIConsole::say("Rebuilding elements from <font color='green'>{$offset}</font>, <font color='yellow'>{$chunk_size}</font> count... " , false);

            $query_chunk_data = " SELECT * FROM {$mysql_table} ";
            $query_chunk_data.= $condition != '' ? " WHERE {$condition} " : '';
            $query_chunk_data.= " ORDER BY id DESC LIMIT {$offset}, {$chunk_size} ";
            
            /*$query_chunk_data = vsprintf("SELECT * FROM %s WHERE 1 = 1 %s ORDER BY id DESC LIMIT %s, %s", [
                $mysql_table, ($condition != '' ? " AND {$condition} " : ''), $offset, $chunk_size
            ]);*/

            $sth = $mysql_connection->query($query_chunk_data);

            // iterate inside chunk
            while ($item = $sth->fetch()) {
                if ($this->rai_options['log_rows_inside_chunk'])
                    CLIConsole::say("{$mysql_table}: {$item['id']}");

                $update_set = $make_updateset_method($item);

                list($update_query, $new_update_set) = self::buildReplaceQueryMVA($sphinx_index, $update_set, $mva_indexes_list);

                $update_statement = $sphinx_connection->prepare($update_query);
                $update_statement->execute($new_update_set);
                $total_updated++;
            } // while

            $breakline_after_chunk = !$this->rai_options['sleep_after_chunk'];

            if ($this->rai_options['log_after_chunk']) {
                CLIConsole::say("Updated RT-index <font color='yellow'>{$sphinx_index}</font>.", $breakline_after_chunk);
            } else {
                CLIConsole::say("<strong>Ok</strong>", $breakline_after_chunk);
            }

            if ($this->rai_options['sleep_after_chunk']) {
                CLIConsole::say("  ZZZZzzz for {$this->rai_options['sleep_time']} second(s)... ", FALSE);
                sleep($this->rai_options['sleep_time']);
                CLIConsole::say("I woke up!");
            }
        } // for
        if ($this->rai_options['log_after_index'])
            CLIConsole::say("Total updated <strong>{$total_updated}</strong> elements for <font color='yellow'>{$sphinx_index}</font> RT-index. <br>");

        return $total_updated;
    } // rebuildAbstractIndexMVA

    /**
     * Применять как:
     *
     * list($update_query, $newdataset) = BuildReplaceQueryMVA($table, $original_dataset, $mva_attributes_list);
     * $update_statement = $sphinx->prepare($update_query);
     * $update_statement->execute($newdataset);
     *
     *
     * @param string $table             -- имя таблицы
     * @param array $dataset            -- сет данных.
     * @param array $mva_attributes     -- массив с именами ключей MVA-атрибутов (они вставятся как значения, а не как placeholder-ы)
     * @return array                    -- возвращает массив с двумя значениями. Первый ключ - запрос, сет данных, очищенный от MVA-атрибутов.
     */
    private static function buildReplaceQueryMVA(string $table, array $dataset, array $mva_attributes):array
    {
        $query = "REPLACE INTO `{$table}` (";

        $dataset_keys = array_keys($dataset);

        $query .= implode(', ', array_map(function ($i){
            return "`{$i}`";
        }, $dataset_keys));

        $query .= " ) VALUES ( ";

        $query .= implode(', ', array_map(function ($i) use ($mva_attributes, $dataset){
            return in_array($i, $mva_attributes) ? "({$dataset[$i]})" : ":{$i}";
        }, $dataset_keys));

        $query .= " ) ";

        $new_dataset = array_filter($dataset, function ($value, $key) use ($mva_attributes) {
            return !in_array($key, $mva_attributes);
        }, ARRAY_FILTER_USE_BOTH);

        return [
            $query, $new_dataset
        ];
    } // BuildReplaceQueryMVA

    /**
     * @param string $table
     * @param array $dataset
     * @return string
     */
    private static function buildReplaceQuery(string $table, array $dataset):string
    {
        $dataset_keys = array_keys($dataset);

        $query = "REPLACE INTO `{$table}` (";

        $query.= implode(', ', array_map(function ($i){
            return "`{$i}`";
        }, $dataset_keys));

        $query.= " ) VALUES ( ";

        $query.= implode(', ', array_map(function ($i){
            return ":{$i}";
        }, $dataset_keys));

        $query.= " ) ";

        return $query;
    }

    public function checkIndexExist(string $sphinx_index)
    {
        $index_definition = $this->sphinx_connection->query("SHOW TABLES LIKE '{$sphinx_index}' ")->fetchAll();

        return count($index_definition) > 0;
    }
    
    /* =========================== STATIC IMPLEMENTATION ================================= */

    /**
     * rebuild_logging_options
     *
     * @var array
     */
    private static $spql_options = [];

    /**
     * @var Connection
     */
    private static $spql_connection_host;

    /**
     * @var Connection
     */
    private static $spql_connection_port;
    /**
     * @var ConnectionInterface
     */
    private static $spql_connection;
    
    /**
     * @var SphinxQL
     */
    private static $spql_instance;
    
    /**
     * @var LoggerInterface
     */
    private static $spql_logger;
    
    /**
     * @inheritDoc
     */
    public static function init(string $sphinx_connection_host, string $sphinx_connection_port, $options = [], LoggerInterface $logger = null)
    {
        self::$spql_connection_host = $sphinx_connection_host;
        self::$spql_connection_port = $sphinx_connection_port;

        self::$spql_options['chunk_length']          = SphinxToolkitHelper::setOption($options, 'chunk_length', 500);

        self::$spql_options['log_rows_inside_chunk'] = SphinxToolkitHelper::setOption($options, 'log_rows_inside_chunk', true);
        self::$spql_options['log_total_rows_found']  = SphinxToolkitHelper::setOption($options, 'log_total_rows_found', true);

        self::$spql_options['log_before_chunk']      = SphinxToolkitHelper::setOption($options, 'log_before_chunk', true);
        self::$spql_options['log_after_chunk']       = SphinxToolkitHelper::setOption($options, 'log_after_chunk', true);

        self::$spql_options['sleep_after_chunk']     = SphinxToolkitHelper::setOption($options, 'sleep_after_chunk', true);

        self::$spql_options['sleep_time'] = SphinxToolkitHelper::setOption($options, 'sleep_time', 1);
        if (self::$spql_options['sleep_time'] == 0) {
            self::$spql_options['sleep_after_chunk'] = false;
        }

        self::$spql_options['log_before_index']      = SphinxToolkitHelper::setOption($options, 'log_before_index', true);
        self::$spql_options['log_after_index']       = SphinxToolkitHelper::setOption($options, 'log_after_index', true);
        
        self::$spql_logger = $logger;
    }
    
    /**
     * @inheritDoc
     */
    public static function initConnection()
    {
        $connection = new Connection();
        $connection->setParams([
            'host' => self::$spql_connection_host,
            'port' => self::$spql_connection_port
        ]);

        return $connection;
    }
    
    /**
     * @inheritDoc
     */
    public static function getInstance($connection)
    {
        return (new SphinxQL($connection));
    }
    
    /**
     * @inheritDoc
     */
    public static function createInstance()
    {
        self::$spql_connection = self::initConnection();
        self::$spql_instance = self::getInstance(self::$spql_connection);
        
        return self::$spql_instance;
    }
    
    /**
     * @inheritDoc
     */
    public static function rt_ReplaceIndex(string $index_name, array $updateset)
    {
        if (empty($updateset)) return null;

        return self::createInstance()
            ->replace()
            ->into($index_name)
            ->set($updateset)
            ->execute();
    }
    
    /**
     * @inheritDoc
     */
    public static function rt_UpdateIndex(string $index_name, array $updateset)
    {
        if (empty($updateset)) return null;

        return self::createInstance()
            ->update($index_name)
            ->into($index_name)
            ->set($updateset)
            ->execute();
    }
    
    /**
     * @inheritDoc
     */
    public static function rt_DeleteIndex(string $index_name, string $field, $field_value = null)
    {
        if (is_null($field_value)) return null;

        return self::createInstance()
            ->delete()
            ->from($index_name)
            ->where($field, '=', $field_value)
            ->execute();
    }
    
    /**
     * @inheritDoc
     */
    public static function rt_DeleteIndexMatch(string $index_name, string $field, $field_value = '')
    {
        if (is_null($field_value)) return null;
    
        return self::createInstance()
            ->delete()
            ->from($index_name)
            ->match($field, $field_value)
            ->execute();
    }
    
    /**
     * @inheritDoc
     */
    public static function rt_TruncateIndex(string $index_name, bool $is_reconfigure = true)
    {
        if (empty($index_name)) return false;
        $with = $is_reconfigure ? 'WITH RECONFIGURE' : '';
        
        return (bool)self::createInstance()->query("TRUNCATE RTINDEX {$index_name} {$with}");
    }
    
    /**
     * @inheritDoc
     */
    public static function rt_RebuildAbstractIndex(PDO $pdo_connection, string $sql_source_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '')
    {
        $chunk_size = self::$spql_options['chunk_length'];

        self::rt_TruncateIndex($sphinx_index);

        $total_count
            = $pdo_connection
            ->query("SELECT COUNT(*) as cnt FROM {$sql_source_table} " . ($condition != '' ? " WHERE {$condition} " : ' ') )
            ->fetchColumn();
        
        $total_updated = 0;

        if (self::$spql_options['log_before_index']) CLIConsole::say("<font color='yellow'>[{$sphinx_index}]</font> index : ", false);

        if (self::$spql_options['log_total_rows_found']) CLIConsole::say("<font color='green'>{$total_count}</font> elements found for rebuild.");

        // iterate chunks
        for ($i = 0; $i < ceil($total_count / $chunk_size); $i++) {
            $offset = $i * $chunk_size;

            if (self::$spql_options['log_before_chunk']) CLIConsole::say("Rebuilding elements from <font color='green'>{$offset}</font>, <font color='yellow'>{$chunk_size}</font> count... " , false);

            $query_chunk_data = "SELECT * FROM {$sql_source_table} ";
            $query_chunk_data.= $condition != '' ? " WHERE {$condition} " : ' ';
            $query_chunk_data.= "ORDER BY id DESC LIMIT {$offset}, {$chunk_size} ";

            $sth = $pdo_connection->query($query_chunk_data);

            // iterate inside chunk
            while ($item = $sth->fetch()) {
                if (self::$spql_options['log_rows_inside_chunk']) CLIConsole::say("{$sql_source_table}: {$item['id']}");

                $update_set = $make_updateset_method($item); // call closure

                self::internal_ReplaceIndex($sphinx_index, $update_set);

                $total_updated++;
            } // while

            $breakline_after_chunk = !self::$spql_options['sleep_after_chunk'];

            if (self::$spql_options['log_after_chunk']) {
                CLIConsole::say("Updated RT-index <font color='yellow'>{$sphinx_index}</font>.", $breakline_after_chunk);
            } else {
                CLIConsole::say("<strong>Ok</strong>", $breakline_after_chunk);
            }

            if (self::$spql_options['sleep_after_chunk']) {
                CLIConsole::say("  ZZZZzzz for " . self::$spql_options['sleep_time'] . " second(s)... ", FALSE);
                sleep(self::$spql_options['sleep_time']);
                CLIConsole::say("I woke up!");
            }
        } // for
        
        if (self::$spql_options['log_after_index']) CLIConsole::say("Total updated <strong>{$total_updated}</strong> elements for <font color='yellow'>{$sphinx_index}</font> RT-index. <br>");

        return $total_updated;
    }
    
    
    /**
     *
     *
     * @param string $search_query
     * @param string $source_index
     * @param string $sort_field
     * @param string $sort_order
     * @param int $limit
     * @param array $option_weight
     * @return array
     * 
     * @throws ConnectionException
     * @throws DatabaseException
     * @throws SphinxQLException
     */
    public static function spql_getDataSet(string $search_query, string $source_index, string $sort_field, string $sort_order = 'DESC', int $limit = 5, array $option_weight = []): array
    {
        $found_dataset = [];
        $compiled_request = '';
        
        if (empty($source_index)) return $found_dataset;
        
        try {
            $search_request = self::createInstance()
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
            
            $meta = SphinxToolkitHelper::showMeta(self::$spql_connection);
            
            self::$spql_logger->error(
                __CLASS__ . '/' . __METHOD__ .
                " Error fetching data from `{$source_index}` : " . $e->getMessage(),
                [
                    $e->getCode(),
                    htmlspecialchars(urldecode($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])),
                    $search_request->getCompiled(),
                    $meta
                ]
            );
        }
        return $found_dataset;
    } // get_IDs_DataSet()
    
    
    /**
     *
     * @param string $index_name
     * @param array $updateset
     * @return ResultSetInterface|null
     * @throws DatabaseException
     * @throws ConnectionException
     * @throws SphinxQLException
     */
    private static function internal_ReplaceIndex(string $index_name, array $updateset)
    {
        if (empty($updateset)) return null;

        return self::getInstance(self::$spql_connection)
            ->replace()
            ->into($index_name)
            ->set($updateset)
            ->execute();
    }
    
    
    
}

# -eof-
