<?php

namespace Arris\Toolkit;

use Foolz\SphinxQL\Drivers\ConnectionInterface;
use Foolz\SphinxQL\Helper;

/**
 * Trait SphinxToolkitHelper
 *
 * Статические функции импортированы из karelwintersky/arris (цель: минимизация зависимостей)
 *
 * @package Arris\Toolkit
 */
class SphinxToolkitHelper
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
        if (!\is_array($options)) {
            return $default_value;
        }
    
        if (\is_null($key)) {
            return $default_value;
        }
    
        return \array_key_exists($key, $options) ? $options[ $key ] : $default_value;
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
    public static function mb_trim_text(string $input, int $length, bool $ellipses = true, bool $strip_html = true, string $ellipses_text = '...'):string
    {
        //strip tags, if desired
        if ($strip_html) {
            $input = \strip_tags($input);
        }
    
        //no need to trim, already shorter than trim length
        if (\mb_strlen($input) <= $length) {
            return $input;
        }
    
        //find last space within length
        $last_space = \mb_strrpos(mb_substr($input, 0, $length), ' ');
        $trimmed_text = \mb_substr($input, 0, $last_space);
    
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
     * @param string $subject the source string
     * @param int             &$count  number of matches found
     *
     * @return string replaced string
     * @author Rodney Rehm, imported from Smarty
     *
     */
    public static function mb_str_replace($search, $replace, string $subject, int &$count = 0)
    {
        if (!\is_array($search) && \is_array($replace)) {
            return false;
        }

        if (\is_array($subject)) {
            // call mb_replace for each single string in $subject
            foreach ($subject as &$string) {
                $string = SphinxToolkitHelper::mb_str_replace($search, $replace, $string, $c);
                $count += $c;
            }
        } elseif (\is_array($search)) {
            if (!\is_array($replace)) {
                foreach ($search as &$string) {
                    $subject = SphinxToolkitHelper::mb_str_replace($string, $replace, $subject, $c);
                    $count += $c;
                }
            } else {
                $n = \max(\count($search), \count($replace));
                while ($n--) {
                    $subject = SphinxToolkitHelper::mb_str_replace(\current($search), \current($replace), $subject, $c);
                    $count += $c;
                    \next($search);
                    \next($replace);
                }
            }
        } else {
            $parts = \mb_split(preg_quote($search), $subject);
            $count = \count($parts) - 1;
            $subject = \implode($replace, $parts);
        }
        return $subject;
    }
    
    /**
     * @param $connection
     * @param $index
     *
     * @return array
     */
    public static function RTIndexGetStatus($connection, $index): array
    {
        $query = "SHOW INDEX {$index} STATUS";
        
        $sth = $connection->query($query);
        
        $set = $sth->fetchAll();
        
        $json_set = [
            'query_time_1min', 'query_time_5min', 'query_time_15min', 'query_time_total',
            'found_rows_1min', 'found_rows_5min', 'found_rows_15min', 'found_rows_total'
        ];
        foreach ($json_set as $key) {
            if (\array_key_exists($key, $set)) {
                $set[$key] = \json_decode($set[$key], true);
            }
        }
        
        return $set;
    }
    
    /**
     * @param $connection
     * @param $index
     * @return false|\PDOStatement
     */
    public static function RTIndexOptimize($connection, $index)
    {
        $query = "OPTIMIZE INDEX {$index}";
        return $connection->query($query);
    }
    
    /**
     * @param $connection
     * @param $index
     * @param bool $reconfigure
     * @return bool
     */
    public static function RTIndexTruncate($connection, $index, bool $reconfigure = true): bool
    {
        $with = $reconfigure ? 'WITH RECONFIGURE' : '';
        return (bool)$connection->query("TRUNCATE RTINDEX {$index} {$with}");
    }
    
    /**
     * @param $connection
     * @param $index
     * @return bool
     */
    public static function RTIndexCheckExist($connection, $index)
    {
        $index_definition = $connection->query("SHOW TABLES LIKE '{$index}' ")->fetchAll();
    
        return \count($index_definition) > 0;
    }
    
    public static function MySQL_GetRowsCount($pdo, string $table, string $condition)
    {
        $query = "SELECT COUNT(*) AS cnt FROM {$table}";
        if ($condition != '') {
            $query .= " WHERE {$condition}";
        }
    
        return $pdo->query($query)->fetchColumn();
    }
    
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
        
        $target = \strip_tags($source);
        
        $target = SphinxToolkitHelper::mb_str_replace($needle, $opts['before_match'] . $needle . $opts['after_match'], $target);
        
        if (($opts['limit'] > 0) && ( \mb_strlen($source) > $opts['limit'] )) {
            $target = SphinxToolkitHelper::mb_trim_text($target, $opts['limit'] ,true,false, $opts['chunk_separator']);
        }
        
        return $target;
    } // EmulateBuildExcerpts
    
    /**
     *
     * @param ConnectionInterface $connection
     * @return array
     *
     * @throws \Foolz\SphinxQL\Exception\ConnectionException
     * @throws \Foolz\SphinxQL\Exception\DatabaseException
     * @throws \Foolz\SphinxQL\Exception\SphinxQLException
     */
    public static function showMeta(ConnectionInterface $connection): array
    {
        return (new Helper($connection))->showMeta()->execute()->fetchAllAssoc();
    }
    
    /**
     * Возвращает версию поискового движка
     *
     * @param ConnectionInterface $connection
     * @throws \Foolz\SphinxQL\Exception\ConnectionException
     * @throws \Foolz\SphinxQL\Exception\DatabaseException
     */
    public static function getVersion(ConnectionInterface $connection)
    {
        $connection->query("SHOW STATUS LIKE 'version%'")->fetchAssoc()['version'];
    }
    
    
    
}