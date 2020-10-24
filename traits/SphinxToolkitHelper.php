<?php

namespace Arris\Toolkit;

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
                $string = \Arris\mb_str_replace($search, $replace, $string, $c);
                $count += $c;
            }
        } elseif (is_array($search)) {
            if (!is_array($replace)) {
                foreach ($search as &$string) {
                    $subject = \Arris\mb_str_replace($string, $replace, $subject, $c);
                    $count += $c;
                }
            } else {
                $n = max(count($search), count($replace));
                while ($n--) {
                    $subject = \Arris\mb_str_replace(current($search), current($replace), $subject, $c);
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
    
}