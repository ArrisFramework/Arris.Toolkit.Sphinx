<?php

namespace Arris\Toolkit;

use Closure;
use Exception;
use PDO;

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
     */
    public function __construct(PDO $mysql_connection, PDO $sphinx_connection);

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

    /**
     * Эмулирует BuildExcerpts из SphinxAPI
     *
     * @param $source
     * @param $needle
     * @param $options
     * 'before_match' => '<strong>',    // Строка, вставляемая перед ключевым словом. По умолчанию "<strong>".
     * 'after_match' => '</strong>',    // Строка, вставляемая после ключевого слова. По умолчанию "</strong>".
     * 'chunk_separator' => '...',      // Строка, вставляемая между частями фрагмента. по умолчанию "...".
     *
     * опции 'limit', 'around', 'exact_phrase' и 'single_passage' в эмуляции не реализованы
     *
     * @return mixed
     */
    public static function EmulateBuildExcerpts($source, $needle, $options);
}