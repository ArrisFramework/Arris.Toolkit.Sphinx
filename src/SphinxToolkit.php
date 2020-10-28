<?php

namespace Arris\Toolkit;

use Closure;
use Exception;
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

    public function __construct(PDO $mysql_connection, PDO $sphinx_connection)
    {
        $this->mysql_connection = $mysql_connection;
        $this->sphinx_connection = $sphinx_connection;
    }

    public function setRebuildIndexOptions(array $options = []):array
    {
        // на самом деле разворачиваем опции с установкой дефолтов
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
    
    
    
    /* =========================== СТАТИЧЕСКИЕ МЕТОДЫ ==================================== */

    public static function EmulateBuildExcerpts($source, $needle, $options)
    {
        $opts = [
            // Строка, вставляемая перед ключевым словом. По умолчанию "<strong>".
            'before_match'  =>  SphinxToolkitHelper::setOption($options, 'before_match', '<strong>'),

            // Строка, вставляемая после ключевого слова. По умолчанию "</strong>".
            'after_match'   =>  SphinxToolkitHelper::setOption($options, 'after_match', '</strong>'),
            // Строка, вставляемая между частями фрагмента. по умолчанию "...".
            'chunk_separator' => '...',

            // НЕ РЕАЛИЗОВАНО: Максимальный размер фрагмента в символах. Integer, по умолчанию 256.
            'limit'         =>  SphinxToolkitHelper::setOption($options, 'limit', 256),

            // НЕ РЕАЛИЗОВАНО: Сколько слов необходимо выбрать вокруг каждого совпадающего с ключевыми словами блока. Integer, по умолчанию 5.
            'around'         =>  SphinxToolkitHelper::setOption($options, 'around', 5),

            // НЕ РЕАЛИЗОВАНО: Необходимо ли подсвечивать только точное совпадение с поисковой фразой, а не отдельные ключевые слова. Boolean, по умолчанию FALSE.
            'exact_phrase'         =>  SphinxToolkitHelper::setOption($options, 'around', null),

            // НЕ РЕАЛИЗОВАНО: Необходимо ли извлечь только единичный наиболее подходящий фрагмент. Boolean, по умолчанию FALSE.
            'single_passage'         =>  SphinxToolkitHelper::setOption($options, 'single_passage', null),

        ];

        $target = strip_tags($source);

        $target = SphinxToolkitHelper::mb_str_replace($needle, $opts['before_match'] . $needle . $opts['after_match'], $target);

        if (($opts['limit'] > 0) && ( mb_strlen($source) > $opts['limit'] )) {
            $target = SphinxToolkitHelper::mb_trim_text($target, $opts['limit'] ,true,false, $opts['chunk_separator']);
        }

        return $target;
    } // EmulateBuildExcerpts

    /* =========================== STATIC IMPLEMENTATION ================================= */

    /**
     * rebuild_logging_options
     *
     * @var array
     */
    private static $rlo = [];

    /**
     * @var Connection
     */
    private static $spql_connection_host;

    /**
     * @var Connection
     */
    private static $spql_connection_port;
    /**
     * @var Connection
     */
    private static $spql_connection;

    public static function init(string $sphinx_connection_host, string $sphinx_connection_port, $options = [])
    {
        self::$spql_connection_host = $sphinx_connection_host;
        self::$spql_connection_port = $sphinx_connection_port;

        self::$rlo['chunk_length']          = SphinxToolkitHelper::setOption($options, 'chunk_length', 500);

        self::$rlo['log_rows_inside_chunk'] = SphinxToolkitHelper::setOption($options, 'log_rows_inside_chunk', true);
        self::$rlo['log_total_rows_found']  = SphinxToolkitHelper::setOption($options, 'log_total_rows_found', true);

        self::$rlo['log_before_chunk']      = SphinxToolkitHelper::setOption($options, 'log_before_chunk', true);
        self::$rlo['log_after_chunk']       = SphinxToolkitHelper::setOption($options, 'log_after_chunk', true);

        self::$rlo['sleep_after_chunk']     = SphinxToolkitHelper::setOption($options, 'sleep_after_chunk', true);

        self::$rlo['sleep_time'] = SphinxToolkitHelper::setOption($options, 'sleep_time', 1);
        if (self::$rlo['sleep_time'] == 0) {
            self::$rlo['sleep_after_chunk'] = false;
        }

        self::$rlo['log_before_index']      = SphinxToolkitHelper::setOption($options, 'log_before_index', true);
        self::$rlo['log_after_index']       = SphinxToolkitHelper::setOption($options, 'log_after_index', true);
    }

    public static function initConnection()
    {
        $conn = new Connection();
        $conn->setParams([
            'host' => self::$spql_connection_host,
            'port' => self::$spql_connection_port
        ]);

        self::$spql_connection = $conn;
    }

    public static function getInstance()
    {
        return (new SphinxQL(self::$spql_connection));
    }

    public static function createInstance()
    {
        $conn = new Connection();
        $conn->setParams([
            'host' => self::$spql_connection_host,
            'port' => self::$spql_connection_port
        ]);

        return (new SphinxQL($conn));
    }

    public static function rt_ReplaceIndex(string $index_name, array $updateset)
    {
        if (empty($updateset)) return null;

        return self::createInstance()
            ->replace()
            ->into($index_name)
            ->set($updateset)
            ->execute();
    }

    public static function rt_UpdateIndex(string $index_name, array $updateset)
    {
        if (empty($updateset)) return null;

        return self::createInstance()
            ->update($index_name)
            ->into($index_name)
            ->set($updateset)
            ->execute();
    }

    public static function rt_DeleteIndex(string $index_name, string $field, $field_value = null)
    {
        if (is_null($field_value)) return null;

        return self::createInstance()
            ->delete()
            ->from($index_name)
            ->where($field, '=', (int)$field_value)
            ->execute();
    }
    
    /**
     * Делает truncate index с реконфигурацией по умолчанию
     *
     * @param string $index_name
     * @param bool $is_reconfigure
     * @return bool
     */
    public static function rt_TruncateIndex(string $index_name, bool $is_reconfigure = true)
    {
        if (empty($index_name)) return false;
        $with = $is_reconfigure ? 'WITH RECONFIGURE' : '';
        
        return (bool)self::createInstance()->query("TRUNCATE RTINDEX {$index_name} {$with}");
    }

    public static function rt_RebuildAbstractIndex(PDO $pdo_connection, string $sql_source_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '')
    {
        $chunk_size = self::$rlo['chunk_length'];

        self::rt_TruncateIndex($sphinx_index);

        $total_count
            = $pdo_connection
            ->query("SELECT COUNT(*) as cnt FROM {$sql_source_table} " . ($condition != '' ? " WHERE {$condition} " : ' ') )
            ->fetchColumn();
        $total_updated = 0;

        if (self::$rlo['log_before_index'])
            CLIConsole::say("<font color='yellow'>[{$sphinx_index}]</font> index : ", false);

        if (self::$rlo['log_total_rows_found'])
            CLIConsole::say("<font color='green'>{$total_count}</font> elements found for rebuild.");

        // iterate chunks
        for ($i = 0; $i < ceil($total_count / $chunk_size); $i++) {
            $offset = $i * $chunk_size;

            if (self::$rlo['log_before_chunk']) CLIConsole::say("Rebuilding elements from <font color='green'>{$offset}</font>, <font color='yellow'>{$chunk_size}</font> count... " , false);

            $query_chunk_data = "SELECT * FROM {$sql_source_table} ";
            $query_chunk_data.= $condition != '' ? " WHERE {$condition} " : ' ';
            $query_chunk_data.= "ORDER BY id DESC LIMIT {$offset}, {$chunk_size} ";

            $sth = $pdo_connection->query($query_chunk_data);

            // iterate inside chunk
            while ($item = $sth->fetch()) {
                if (self::$rlo['log_rows_inside_chunk'])
                    CLIConsole::say("{$sql_source_table}: {$item['id']}");

                $update_set = $make_updateset_method($item);

                self::internal_ReplaceIndex($sphinx_index, $update_set);

                $total_updated++;
            } // while

            $breakline_after_chunk = !self::$rlo['sleep_after_chunk'];

            if (self::$rlo['log_after_chunk']) {
                CLIConsole::say("Updated RT-index <font color='yellow'>{$sphinx_index}</font>.", $breakline_after_chunk);
            } else {
                CLIConsole::say("<strong>Ok</strong>", $breakline_after_chunk);
            }

            if (self::$rlo['sleep_after_chunk']) {
                CLIConsole::say("  ZZZZzzz for " . self::$rlo['sleep_time'] . " second(s)... ", FALSE);
                sleep(self::$rlo['sleep_time']);
                CLIConsole::say("I woke up!");
            }
        } // for
        if (self::$rlo['log_after_index'])
            CLIConsole::say("Total updated <strong>{$total_updated}</strong> elements for <font color='yellow'>{$sphinx_index}</font> RT-index. <br>");

        return $total_updated;
    }

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

        return self::getInstance()
            ->replace()
            ->into($index_name)
            ->set($updateset)
            ->execute();
    }


}

# -eof-
