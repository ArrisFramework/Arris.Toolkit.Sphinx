<?php

namespace Arris\Toolkit;

use Closure;
use Exception;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Interface __SphinxToolkitMysqliInterface
 *
 *
 *
 * @package Arris\Toolkit
 */
interface SphinxToolkitMysqliInterface {
    
    /**
     * SphinxToolkit constructor.
     *
     * @param PDO $mysql_connection
     * @param PDO $sphinx_connection
     * @param LoggerInterface|null $logger
     */
    public function __construct(PDO $mysql_connection, PDO $sphinx_connection, LoggerInterface $logger = null);

    /**
     * Устанавливает опции для перестроителя RT-индекса
     *
     * @param array $options - новый набор опций
     * @return array - результирующий набор опций
     */
    public function setRebuildIndexOptions(array $options = []):array;

    /**
     * Перестраивает RT-индекс
     *
     * @param string $mysql_table               -- SQL-таблица исходник
     * @param string $sphinx_index              -- имя индекса (таблицы)
     * @param Closure $make_updateset_method    -- замыкание, анонимная функция, преобразующая исходный набор данных в то, что вставляется в индекс
     * @param string $condition                 -- условие выборки из исходной таблицы (без WHERE !!!)
     *
     * @param bool $use_mva                     -- используются ли MultiValued-атрибуты в наборе данных?
     * @param array $mva_indexes_list           -- список MVA-индексов, значения которых не нужно биндить через плейсхолдеры
     *
     * @return int                              -- количество обновленных записей в индексе
     * @throws Exception
     */
    public function rebuildAbstractIndex(string $mysql_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '', bool $use_mva = false, array $mva_indexes_list = []):int;

    /**
     * DEPRECATED
     *
     * @param string $mysql_table               -- SQL-таблица исходник
     * @param string $sphinx_index              -- имя индекса (таблицы)
     * @param Closure $make_updateset_method    -- замыкание, анонимная функция, преобразующая исходный набор данных в то, что вставляется в индекс
     * @param string $condition                 -- условие выборки из исходной таблицы (без WHERE !!!)
     * @param array $mva_indexes_list           -- список MVA-индексов, значения которых не нужно биндить через плейсхолдеры
     *
     * @return int
     * @throws Exception
     */
    public function rebuildAbstractIndexMVA(string $mysql_table, string $sphinx_index, Closure $make_updateset_method, string $condition = '', array $mva_indexes_list = []):int;

}