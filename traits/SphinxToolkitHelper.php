<?php

namespace Arris\Toolkit;

use PDO;

/**
 * Trait SphinxToolkitHelper
 *
 * Статические функции импортированы из karelwintersky/arris (цель: минимизация зависимостей)
 *
 * @package Arris\Toolkit
 */
trait SphinxToolkitHelper
{
    /**
     *
     * @param array $options
     * @param null $key
     * @param null $default_value
     * @return mixed|null
     */
    public static function setOption(array $options = [], $key = null, $default_value = null)
    {
        if (!is_array($options)) return $default_value;
    
        if (is_null($key)) return $default_value;
    
        return array_key_exists($key, $options) ? $options[ $key ] : $default_value;
    }
    
    /**
     * trims text to a space then adds ellipses if desired
     * @param string $input text to trim
     * @param int $length in characters to trim to
     * @param bool $ellipses if ellipses (...) are to be added
     * @param bool $strip_html if html tags are to be stripped
     * @param string $ellipses_text text to be added as ellipses
     * @return string
     *
     * http://www.ebrueggeman.com/blog/abbreviate-text-without-cutting-words-in-half
     *
     * еще есть вариант: https://stackoverflow.com/questions/8286082/truncate-a-string-in-php-without-cutting-words (но без обработки тегов)
     * https://www.php.net/manual/ru/function.wordwrap.php - см комментарии
     */
    public static function mb_trim_text($input, $length, $ellipses = true, $strip_html = true, $ellipses_text = '...'):string
    {
        //strip tags, if desired
        if ($strip_html) {
            $input = strip_tags($input);
        }
    
        //no need to trim, already shorter than trim length
        if (mb_strlen($input) <= $length) {
            return $input;
        }
    
        //find last space within length
        $last_space = mb_strrpos(mb_substr($input, 0, $length), ' ');
        $trimmed_text = mb_substr($input, 0, $last_space);
    
        //add ellipses (...)
        if ($ellipses) {
            $trimmed_text .= $ellipses_text;
        }
    
        return $trimmed_text;
    }
    
    /**
     * Multibyte string replace
     *
     * @param string|string[] $search  the string to be searched
     * @param string|string[] $replace the replacement string
     * @param string          $subject the source string
     * @param int             &$count  number of matches found
     *
     * @return string replaced string
     * @author Rodney Rehm, imported from Smarty
     *
     */
    public static function mb_str_replace($search, $replace, $subject, &$count = 0)
    {
        if (!is_array($search) && is_array($replace)) {
            return false;
        }
        if (is_array($subject)) {
            // call mb_replace for each single string in $subject
            foreach ($subject as &$string) {
                $string = SphinxToolkitHelper::mb_str_replace($search, $replace, $string, $c);
                $count += $c;
            }
        } elseif (is_array($search)) {
            if (!is_array($replace)) {
                foreach ($search as &$string) {
                    $subject = SphinxToolkitHelper::mb_str_replace($string, $replace, $subject, $c);
                    $count += $c;
                }
            } else {
                $n = max(count($search), count($replace));
                while ($n--) {
                    $subject = SphinxToolkitHelper::mb_str_replace(current($search), current($replace), $subject, $c);
                    $count += $c;
                    next($search);
                    next($replace);
                }
            }
        } else {
            $parts = mb_split(preg_quote($search), $subject);
            $count = count($parts) - 1;
            $subject = implode($replace, $parts);
        }
        return $subject;
    }
    
    /**
     * @param PDO $connection
     * @param $index
     *
     * @return array
     */
    public static function RTIndexGetStatus(PDO $connection, $index)
    {
        $query = "SHOW INDEX {$index} STATUS";
        
        $sth = $connection->query($query);
        
        $set = $sth->fetchAll();
        
        $json_set = [
            'query_time_1min', 'query_time_5min', 'query_time_15min', 'query_time_total',
            'found_rows_1min', 'found_rows_5min', 'found_rows_15min', 'found_rows_total'
        ];
        foreach ($json_set as $key) {
            if (array_key_exists($key, $set)) {
                $set[$key] = json_decode($set[$key], true);
            }
        }
        
        return $set;
    }
    
    /**
     * @param PDO $connection
     * @param $index
     * @return false|\PDOStatement
     */
    public static function RTIndexOptimize(PDO $connection, $index)
    {
        $query = "OPTIMIZE INDEX {$index}";
        return $connection->query($query);
    }
    
    /**
     * @param PDO $connection
     * @param $index
     * @param bool $reconfigure
     * @return bool
     */
    public static function RTIndexTruncate(PDO $connection, $index, $reconfigure = true)
    {
        $with = $reconfigure ? 'WITH RECONFIGURE' : '';
        return (bool)$connection->query("TRUNCATE RTINDEX {$index} {$with}");
    }
    
    /**
     * @param PDO $connection
     * @param $index
     * @return bool
     */
    public static function RTIndexCheckExist(PDO $connection, $index)
    {
        $index_definition = $connection->query("SHOW TABLES LIKE '{$index}' ")->fetchAll();
    
        return count($index_definition) > 0;
    }
    
    public static function MySQL_GetRowsCount(PDO $pdo, string $table, string $condition)
    {
        $query = "SELECT COUNT(*) AS cnt FROM {$table}";
        if ($condition != '') $query .= " WHERE {$condition}";
    
        return $pdo->query($query)->fetchColumn();
    }
    
}