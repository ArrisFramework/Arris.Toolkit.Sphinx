<?php

namespace Arris\Toolkit;

use Closure;
use Foolz\SphinxQL\Drivers\ConnectionInterface;
use Foolz\SphinxQL\Drivers\ResultSetInterface;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;
use PDO;

/**
 * Interface __SphinxToolkitFoolzInterface
 *
 *
 *
 * @package Arris\Toolkit
 */
interface SphinxToolkitFoolzInterface {

    /**
     * Инициализация статического интерфейса к методам
     *
     * @param string $sphinx_connection_host
     * @param string $sphinx_connection_port
     * @param array $options
     */
    public static function init(string $sphinx_connection_host, string $sphinx_connection_port, $options = []);

    /**
     * Создает коннекшен и устанавливает параметры подключения: хост и порт
     *
     * @return ConnectionInterface
     */
    public static function initConnection();

    /**
     * Создает инстанс SphinxQL (для однократного обновления)
     *
     * @return SphinxQL
     */
    public static function createInstance();

    /**
     * Обновляет (UPDATE) реалтайм-индекс по набору данных
     * с созданием коннекшена "сейчас"
     *
     * @param string $index_name
     * @param array $updateset
     * @return ResultSetInterface|null
     *
     * @throws DatabaseException
     * @throws ConnectionException
     * @throws SphinxQLException
     */
    public static function rt_UpdateIndex(string $index_name, array $updateset);

    /**
     * Замещает (REPLACE) реалтайм-индекс по набору данных
     *
     * @param string $index_name
     * @param array $updateset
     * @return ResultSetInterface|null
     *
     * @throws DatabaseException
     * @throws ConnectionException
     * @throws SphinxQLException
     */
    public static function rt_ReplaceIndex(string $index_name, array $updateset);

    /**
     * Удаляет строку реалтайм-индекса по значению нестрокового поля.
     *
     * @todo: при передаче параметра требуется его приведение к типу поля. Для поля 'id' это тип INT.
     *
     * В случае multi-valued атрибута нужно удалять строки для каждого значения атрибута.
     *
     * @param string $index_name        -- индекс
     * @param string $field             -- поле для поиска индекса
     * @param null $field_value         -- значение для поиска индекса
     * @return ResultSetInterface|null
     *
     * @throws DatabaseException
     * @throws ConnectionException
     * @throws SphinxQLException
     */
    public static function rt_DeleteIndex(string $index_name, string $field, $field_value = null);
    
    /**
     * Удаляет строку реалтайм-индекса по значению текстового поля, например '@title поликлиника'
     * ВАЖНО: пустое значение поля $field_value удалит ВСЕ строки индекса
     *
     * @param string $index_name        -- индекс
     * @param string $field             -- поле для поиска индекса
     * @param string $field_value       -- значение для поиска индекса (важно: тип значения должен совпадать)
     * @return ResultSetInterface|null
     *
     * @throws DatabaseException
     * @throws ConnectionException
     * @throws SphinxQLException
     */
    public static function rt_DeleteIndexMatch(string $index_name, string $field, $field_value = '');
    
    /**
     * Делает truncate index с реконфигурацией по умолчанию
     *
     * @param string $index_name
     * @param bool $is_reconfigure
     * @return bool
     */
    public static function rt_TruncateIndex(string $index_name, bool $is_reconfigure = true);
    
    /**
     * @param PDO $pdo_connection
     * @param string $sql_source_table
     * @param string $sphinx_index
     * @param Closure $make_updateset_method
     * @param string $condition
     * @return int
     * @throws DatabaseException
     * @throws ConnectionException
     * @throws SphinxQLException
     */
    public static function rt_RebuildAbstractIndex(PDO $pdo_connection, string $sql_source_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '');
    
    /**
     * Создает инстанс на основе сохраненного в классе коннекшена
     *
     * @param ConnectionInterface $connection
     * @return SphinxQL
     */
    public static function getInstance($connection);
    
    /**
     * Возвращает META-информацию (после запроса)
     *
     * @throws ConnectionException
     * @throws DatabaseException
     * @throws SphinxQLException
     */
    public static function showMeta();
}