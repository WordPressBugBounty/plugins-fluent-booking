<?php

namespace FluentBooking\Framework\Support;

use Closure;
use ArrayAccess;
use JsonSerializable;
use FluentBooking\Framework\Support\DateTime;
use FluentBooking\Framework\Support\Tappable;
use FluentBooking\Framework\Support\Conditionable;
use FluentBooking\Framework\Support\MacroableTrait;
use FluentBooking\Framework\Support\HelperFunctionsTrait;

class Stringable implements JsonSerializable
{
    use Tappable, Conditionable, MacroableTrait, HelperFunctionsTrait;

    /**
     * The underlying string value.
     *
     * @var string
     */
    protected $value;

    /**
     * Create a new instance of the class.
     *
     * @param  string  $value
     * @return void
     */
    public function __construct($value = '')
    {
        $this->value = (string) $value;
    }

    /**
     * Makes an acronum from a string of words
     * 
     * @param  string $delimiter
     * @return self
     */
    public function acronym(string $delimiter = '')
    {
        return new static(Str::acronym($this->value, $delimiter));
    }

    /**
     * Return the remainder of a string after the first occurrence of a given value.
     *
     * @param  string  $search
     * @return static
     */
    public function after($search)
    {
        return new static(Str::after($this->value, $search));
    }

    /**
     * Return the remainder of a string after the last occurrence of a given value.
     *
     * @param  string  $search
     * @return static
     */
    public function afterLast($search)
    {
        return new static(Str::afterLast($this->value, $search));
    }

    /**
     * Append the given values to the string.
     *
     * @param  array|string  ...$values
     * @return static
     */
    public function append(...$values)
    {
        return new static($this->value.implode('', $values));
    }

    /**
     * Append a new line to the string.
     *
     * @param  int  $count
     * @return $this
     */
    public function newLine($count = 1)
    {
        return $this->append(str_repeat(PHP_EOL, $count));
    }

    /**
     * Transliterate a UTF-8 value to ASCII.
     *
     * @param  string  $language
     * @return static
     */
    public function ascii($language = 'en')
    {
        return new static(Str::ascii($this->value, $language));
    }

    /**
     * Checks if the word(s) are in capitalized form.
     * 
     * @param  boolean $onlyFirst if true, checks only the first charatcer
     * @return boolean
     */
    public function isCapitalized($onlyFirst = false)
    {
        return Str::isCapitalized($this->value, $onlyFirst);
    }

    /**
     * Checks if the first character is in capital form.
     * 
     * @return boolean
     */
    public function isCapital()
    {
        return $this->isCapitalized(true);
    }

    /**
     * Checks if the chracters are in upper case
     * 
     * @return boolean
     */
    public static function isUpper()
    {
        return Str::isUpper($this->value);
    }

    /**
     * Checks if the chracters are in lower case
     * 
     * @return boolean
     */
    public static function isLower()
    {
        return Str::isLower($this->value);
    }

    /**
     * Checks if two words sounds alike
     * 
     * @param string $str
     * 
     * @return bool
     */
    public function soundsAlike($str)
    {
        return Str::soundsAlike($this->value, $str);
    }

    /**
     * Checks if two words are similar
     * 
     * @param string $str
     * @param int $accuracy
     * 
     * @return bool
     */
    public function isSimilar($str, $accuracy = 50)
    {
        return Str::isSimilar($this->value, $str, $accuracy);
    }

    /**
     * Checks if two words are similar
     * 
     * @param string $str
     * 
     * @return bool
     */
    public function similarityOf($str)
    {
        return Str::similarityOf($this->value, $str);
    }

    /**
     * Get the trailing name component of the path.
     *
     * @param  string  $suffix
     * @return static
     */
    public function basename($suffix = '')
    {
        return new static(basename($this->value, $suffix));
    }

    /**
     * Get the character at the specified index.
     *
     * @param  int  $index
     * @return string|false
     */
    public function charAt($index)
    {
        return Str::charAt($this->value, $index);
    }

    /**
     * Get the basename of the class path.
     *
     * @return static
     */
    public function classBasename()
    {
        return new static(static::classBasename($this->value));
    }

    /**
     * Get the portion of a string before the first occurrence of a given value.
     *
     * @param  string  $search
     * @return static
     */
    public function before($search)
    {
        return new static(Str::before($this->value, $search));
    }

    /**
     * Get the portion of a string before the last occurrence of a given value.
     *
     * @param  string  $search
     * @return static
     */
    public function beforeLast($search)
    {
        return new static(Str::beforeLast($this->value, $search));
    }

    /**
     * Get the portion of a string between two given values.
     *
     * @param  string  $from
     * @param  string  $to
     * @return static
     */
    public function between($from, $to)
    {
        return new static(Str::between($this->value, $from, $to));
    }

    /**
     * Get the smallest possible portion of a string between two given values.
     *
     * @param  string  $from
     * @param  string  $to
     * @return static
     */
    public function betweenFirst($from, $to)
    {
        return new static(Str::betweenFirst($this->value, $from, $to));
    }

    /**
     * Convert a value to camel case.
     *
     * @return static
     */
    public function camel()
    {
        return new static(Str::camel($this->value));
    }

    /**
     * Determine if a given string contains a given substring.
     *
     * @param  string|iterable<string>  $needles
     * @param  bool  $ignoreCase
     * @return bool
     */
    public function contains($needles, $ignoreCase = false)
    {
        return Str::contains($this->value, $needles, $ignoreCase);
    }

    /**
     * Determine if a given string contains all array values.
     *
     * @param  iterable<string>  $needles
     * @param  bool  $ignoreCase
     * @return bool
     */
    public function containsAll($needles, $ignoreCase = false)
    {
        return Str::containsAll($this->value, $needles, $ignoreCase);
    }

    /**
     * Replace consecutive instances of a given character with a single character.
     *
     * @param  string  $character
     * @return static
     */
    public function deduplicate(string $character = ' ')
    {
        return new static(Str::deduplicate($this->value, $character));
    }

    /**
     * Get the parent directory's path.
     *
     * @param  int  $levels
     * @return static
     */
    public function dirname($levels = 1)
    {
        return new static(dirname($this->value, $levels));
    }

    /**
     * Determine if a given string ends with a given substring.
     *
     * @param  string|iterable<string>  $needles
     * @return bool
     */
    public function endsWith($needles)
    {
        return Str::endsWith($this->value, $needles);
    }

    /**
     * Determine if the string is an exact match with the given value.
     *
     * @param  \FluentBooking\Framework\Support\Stringable|string  $value
     * @return bool
     */
    public function exactly($value)
    {
        if ($value instanceof Stringable) {
            $value = $value->toString();
        }

        return $this->value === $value;
    }

    /**
     * Explode the string into an array.
     *
     * @param  string  $delimiter
     * @param  int  $limit
     * @return \FluentBooking\Framework\Support\Collection<int, string>
     */
    public function explode($delimiter, $limit = PHP_INT_MAX)
    {
        return Helper::collect(explode($delimiter, $this->value, $limit));
    }

    /**
     * Split a string using a regular expression or by length.
     *
     * @param  string|int  $pattern
     * @param  int  $limit
     * @param  int  $flags
     * @return \FluentBooking\Framework\Support\Collection<int, string>
     */
    public function split($pattern, $limit = -1, $flags = 0)
    {
        if (filter_var($pattern, FILTER_VALIDATE_INT) !== false) {
            return Helper::collect(mb_str_split($this->value, $pattern));
        }

        $segments = preg_split($pattern, $this->value, $limit, $flags);

        return ! empty($segments) ? Helper::collect($segments) : Helper::collect();
    }

    /**
     * Cap a string with a single instance of a given value.
     *
     * @param  string  $cap
     * @return static
     */
    public function finish($cap)
    {
        return new static(Str::finish($this->value, $cap));
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param  string|iterable<string>  $pattern
     * @return bool
     */
    public function is($pattern)
    {
        return Str::is($pattern, $this->value);
    }

    /**
     * Determine if a given string is 7 bit ASCII.
     *
     * @return bool
     */
    public function isAscii()
    {
        return Str::isAscii($this->value);
    }

    /**
     * Determine if a given string is valid JSON.
     *
     * @return bool
     */
    public function isJson()
    {
        return Str::isJson($this->value);
    }

    /**
     * Determine if a given value is a valid URL.
     *
     * @return bool
     */
    public function isUrl()
    {
        return Str::isUrl($this->value);
    }

    /**
     * Determine if a given string is a valid UUID.
     *
     * @return bool
     */
    public function isUuid()
    {
        return Str::isUuid($this->value);
    }

    /**
     * Determine if the given string is empty.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->value === '';
    }

    /**
     * Determine if the given string is not empty.
     *
     * @return bool
     */
    public function isNotEmpty()
    {
        return ! $this->isEmpty();
    }

    /**
     * Convert a string to kebab case.
     *
     * @return static
     */
    public function kebab()
    {
        return new static(Str::kebab($this->value));
    }

    /**
     * Return the length of the given string.
     *
     * @param  string|null  $encoding
     * @return int
     */
    public function length($encoding = null)
    {
        return Str::length($this->value, $encoding);
    }

    /**
     * Limit the number of characters in a string.
     *
     * @param  int  $limit
     * @param  string  $end
     * @return static
     */
    public function limit($limit = 100, $end = '...')
    {
        return new static(Str::limit($this->value, $limit, $end));
    }

    /**
     * Convert the given string to lower-case.
     *
     * @return static
     */
    public function lower()
    {
        return new static(Str::lower($this->value));
    }

    /**
     * Masks a portion of a string with a repeated character.
     *
     * @param  string  $character
     * @param  int  $index
     * @param  int|null  $length
     * @param  string  $encoding
     * @return static
     */
    public function mask($character, $index, $length = null, $encoding = 'UTF-8')
    {
        return new static(Str::mask($this->value, $character, $index, $length, $encoding));
    }

    /**
     * Get the string matching the given pattern.
     *
     * @param  string  $pattern
     * @return static
     */
    public function match($pattern)
    {
        return new static(Str::match($pattern, $this->value));
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param  string|iterable<string>  $pattern
     * @return bool
     */
    public function isMatch($pattern)
    {
        return Str::isMatch($pattern, $this->value);
    }

    /**
     * Get the string matching the given pattern.
     *
     * @param  string  $pattern
     * @return \FluentBooking\Framework\Support\Collection
     */
    public function matchAll($pattern)
    {
        return Str::matchAll($pattern, $this->value);
    }

    /**
     * Determine if the string matches the given pattern.
     *
     * @param  string  $pattern
     * @return bool
     */
    public function test($pattern)
    {
        return $this->isMatch($pattern);
    }

    /**
     * Pad both sides of the string with another.
     *
     * @param  int  $length
     * @param  string  $pad
     * @return static
     */
    public function padBoth($length, $pad = ' ')
    {
        return new static(Str::padBoth($this->value, $length, $pad));
    }

    /**
     * Pad the left side of the string with another.
     *
     * @param  int  $length
     * @param  string  $pad
     * @return static
     */
    public function padLeft($length, $pad = ' ')
    {
        return new static(Str::padLeft($this->value, $length, $pad));
    }

    /**
     * Pad the right side of the string with another.
     *
     * @param  int  $length
     * @param  string  $pad
     * @return static
     */
    public function padRight($length, $pad = ' ')
    {
        return new static(Str::padRight($this->value, $length, $pad));
    }

    /**
     * Parse a Class@method style callback into class and method.
     *
     * @param  string|null  $default
     * @return array<int, string|null>
     */
    public function parseCallback($default = null)
    {
        return Str::parseCallback($this->value, $default);
    }

    /**
     * Parse an integer from a string.
     * 
     * @param  string $value
     * @return int|null
     */
    public static function parseInt($value)
    {
        return Str::parseInt($value);
    }

    /**
     * Parse a floasting point number from a string.
     * 
     * @param  string $value
     * @return float|null
     */
    public static function parseFloat($value)
    {
        return Str::parseFloat($value);
    }

    /**
     * Remove all non-numeric characters from a string.
     *
     * @param  string  $value
     * @return string
     */
    public static function parseNumber($value)
    {
        return Str::parseNumber($value);
    }

    /**
     * Call the given callback and return a new string.
     *
     * @param  callable  $callback
     * @return static
     */
    public function pipe(callable $callback)
    {
        return new static($callback($this));
    }

    /**
     * Get the plural form of an English word.
     *
     * @param  int|array|\Countable  $count
     * @return static
     */
    public function plural($count = 2)
    {
        return new static(Str::plural($this->value, $count));
    }

    /**
     * Pluralize the last word of an English, studly caps case string.
     *
     * @param  int|array|\Countable  $count
     * @return static
     */
    public function pluralStudly($count = 2)
    {
        return new static(Str::pluralStudly($this->value, $count));
    }

    /**
     * Prepend the given values to the string.
     *
     * @param  string  ...$values
     * @return static
     */
    public function prepend(...$values)
    {
        return new static(implode('', $values).$this->value);
    }

    /**
     * Remove any occurrence of the given string in the subject.
     *
     * @param  string|iterable<string>  $search
     * @param  bool  $caseSensitive
     * @return static
     */
    public function remove($search, $caseSensitive = true)
    {
        return new static(Str::remove($search, $this->value, $caseSensitive));
    }

    /**
     * Reverse the string.
     *
     * @return static
     */
    public function reverse()
    {
        return new static(Str::reverse($this->value));
    }

    /**
     * Repeat the string.
     *
     * @param  int  $times
     * @return static
     */
    public function repeat(int $times)
    {
        return new static(str_repeat($this->value, $times));
    }

    /**
     * Replace the given value in the given string.
     *
     * @param  string|iterable<string>  $search
     * @param  string|iterable<string>  $replace
     * @param  bool  $caseSensitive
     * @return static
     */
    public function replace($search, $replace, $caseSensitive = true)
    {
        return new static(Str::replace($search, $replace, $this->value, $caseSensitive));
    }

    /**
     * Replace a given value in the string sequentially with an array.
     *
     * @param  string  $search
     * @param  iterable<string>  $replace
     * @return static
     */
    public function replaceArray($search, $replace)
    {
        return new static(Str::replaceArray($search, $replace, $this->value));
    }

    /**
     * Replace the first occurrence of a given value in the string.
     *
     * @param  string  $search
     * @param  string  $replace
     * @return static
     */
    public function replaceFirst($search, $replace)
    {
        return new static(Str::replaceFirst($search, $replace, $this->value));
    }

    /**
     * Replace the last occurrence of a given value in the string.
     *
     * @param  string  $search
     * @param  string  $replace
     * @return static
     */
    public function replaceLast($search, $replace)
    {
        return new static(Str::replaceLast($search, $replace, $this->value));
    }

    /**
     * Replace the patterns matching the given regular expression.
     *
     * @param  string  $pattern
     * @param  \Closure|string  $replace
     * @param  int  $limit
     * @return static
     */
    public function replaceMatches($pattern, $replace, $limit = -1)
    {
        if ($replace instanceof Closure) {
            return new static(preg_replace_callback($pattern, $replace, $this->value, $limit));
        }

        return new static(preg_replace($pattern, $replace, $this->value, $limit));
    }

    /**
     * Parse input from a string to a collection, according to a format.
     *
     * @param  string  $format
     * @return \FluentBooking\Framework\Support\Collection
     */
    public function scan($format)
    {
        return Helper::collect(sscanf($this->value, $format));
    }

    /**
     * Remove all "extra" blank space from the given string.
     *
     * @return static
     */
    public function squish()
    {
        return new static(Str::squish($this->value));
    }

    /**
     * Begin a string with a single instance of a given value.
     *
     * @param  string  $prefix
     * @return static
     */
    public function start($prefix)
    {
        return new static(Str::start($this->value, $prefix));
    }

    /**
     * Strip HTML and PHP tags from the given string.
     *
     * @param  string  $allowedTags
     * @return static
     */
    public function stripTags($allowedTags = null)
    {
        return new static(strip_tags($this->value, $allowedTags));
    }

    /**
     * Convert the given string to upper-case.
     *
     * @return static
     */
    public function upper()
    {
        return new static(Str::upper($this->value));
    }

    /**
     * Convert the given string to title case.
     *
     * @return static
     */
    public function title()
    {
        return new static(Str::title($this->value));
    }

    /**
     * Convert the given string to title case for each word.
     *
     * @return static
     */
    public function headline()
    {
        return new static(Str::headline($this->value));
    }

    /**
     * Get the singular form of an English word.
     *
     * @return static
     */
    public function singular()
    {
        return new static(Str::singular($this->value));
    }

    /**
     * Generate a URL friendly "slug" from a given string.
     *
     * @param  string  $separator
     * @param  string|null  $language
     * @param  array<string, string>  $dictionary
     * @return static
     */
    public function slug($separator = '-', $language = 'en', $dictionary = ['@' => 'at'])
    {
        return new static(Str::slug($this->value, $separator, $language, $dictionary));
    }

    /**
     * Convert a string to snake case.
     *
     * @param  string  $delimiter
     * @return static
     */
    public function snake($delimiter = '_')
    {
        return new static(Str::snake($this->value, $delimiter));
    }

    /**
     * Determine if a given string starts with a given substring.
     *
     * @param  string|iterable<string>  $needles
     * @return bool
     */
    public function startsWith($needles)
    {
        return Str::startsWith($this->value, $needles);
    }

    /**
     * Convert a value to studly caps case.
     *
     * @return static
     */
    public function studly()
    {
        return new static(Str::studly($this->value));
    }

    /**
     * Returns the portion of the string specified by the start and length parameters.
     *
     * @param  int  $start
     * @param  int|null  $length
     * @param  string  $encoding
     * @return static
     */
    public function substr($start, $length = null, $encoding = 'UTF-8')
    {
        return new static(Str::substr($this->value, $start, $length, $encoding));
    }

    /**
     * Returns the number of substring occurrences.
     *
     * @param  string  $needle
     * @param  int  $offset
     * @param  int|null  $length
     * @return int
     */
    public function substrCount($needle, $offset = 0, $length = null)
    {
        return Str::substrCount($this->value, $needle, $offset, $length);
    }

    /**
     * Replace text within a portion of a string.
     *
     * @param  string|string[]  $replace
     * @param  int|int[]  $offset
     * @param  int|int[]|null  $length
     * @return static
     */
    public function substrReplace($replace, $offset = 0, $length = null)
    {
        return new static(Str::substrReplace($this->value, $replace, $offset, $length));
    }

    /**
     * Swap multiple keywords in a string with other keywords.
     *
     * @param  array  $map
     * @return static
     */
    public function swap(array $map)
    {
        return new static(strtr($this->value, $map));
    }

    /**
     * Trim the string of the given characters.
     *
     * @param  string  $characters
     * @return static
     */
    public function trim($characters = null)
    {
        return new static(trim(...array_merge([$this->value], func_get_args())));
    }

    /**
     * Left trim the string of the given characters.
     *
     * @param  string  $characters
     * @return static
     */
    public function ltrim($characters = null)
    {
        return new static(ltrim(...array_merge([$this->value], func_get_args())));
    }

    /**
     * Right trim the string of the given characters.
     *
     * @param  string  $characters
     * @return static
     */
    public function rtrim($characters = null)
    {
        return new static(rtrim(...array_merge([$this->value], func_get_args())));
    }

    /**
     * Make a string's first character lowercase.
     *
     * @return static
     */
    public function lcfirst()
    {
        return new static(Str::lcfirst($this->value));
    }

    /**
     * Make a string's first character uppercase.
     *
     * @return static
     */
    public function ucfirst()
    {
        return new static(Str::ucfirst($this->value));
    }

    /**
     * Split a string by uppercase characters.
     *
     * @return \FluentBooking\Framework\Support\Collection<int, string>
     */
    public function ucsplit()
    {
        return Helper::collect(Str::ucsplit($this->value));
    }

    /**
     * Execute the given callback if the string contains a given substring.
     *
     * @param  string|iterable<string>  $needles
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenContains($needles, $callback, $default = null)
    {
        return $this->when($this->contains($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string contains all array values.
     *
     * @param  iterable<string>  $needles
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenContainsAll(array $needles, $callback, $default = null)
    {
        return $this->when($this->containsAll($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string is empty.
     *
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenEmpty($callback, $default = null)
    {
        return $this->when($this->isEmpty(), $callback, $default);
    }

    /**
     * Execute the given callback if the string is not empty.
     *
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenNotEmpty($callback, $default = null)
    {
        return $this->when($this->isNotEmpty(), $callback, $default);
    }

    /**
     * Execute the given callback if the string ends with a given substring.
     *
     * @param  string|iterable<string>  $needles
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenEndsWith($needles, $callback, $default = null)
    {
        return $this->when($this->endsWith($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string is an exact match with the given value.
     *
     * @param  string  $value
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenExactly($value, $callback, $default = null)
    {
        return $this->when($this->exactly($value), $callback, $default);
    }

    /**
     * Execute the given callback if the string is not an exact match with the given value.
     *
     * @param  string  $value
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenNotExactly($value, $callback, $default = null)
    {
        return $this->when(! $this->exactly($value), $callback, $default);
    }

    /**
     * Execute the given callback if the string matches a given pattern.
     *
     * @param  string|iterable<string>  $pattern
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenIs($pattern, $callback, $default = null)
    {
        return $this->when($this->is($pattern), $callback, $default);
    }

    /**
     * Execute the given callback if the string is 7 bit ASCII.
     *
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenIsAscii($callback, $default = null)
    {
        return $this->when($this->isAscii(), $callback, $default);
    }

    /**
     * Execute the given callback if the string is a valid UUID.
     *
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenIsUuid($callback, $default = null)
    {
        return $this->when($this->isUuid(), $callback, $default);
    }

    /**
     * Execute the given callback if the string starts with a given substring.
     *
     * @param  string|iterable<string>  $needles
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenStartsWith($needles, $callback, $default = null)
    {
        return $this->when($this->startsWith($needles), $callback, $default);
    }

    /**
     * Execute the given callback if the string matches the given pattern.
     *
     * @param  string  $pattern
     * @param  callable  $callback
     * @param  callable|null  $default
     * @return static
     */
    public function whenTest($pattern, $callback, $default = null)
    {
        return $this->when($this->test($pattern), $callback, $default);
    }

    /**
     * Limit the number of words in a string.
     *
     * @param  int  $words
     * @param  string  $end
     * @return static
     */
    public function words($words = 100, $end = '...')
    {
        return new static(Str::words($this->value, $words, $end));
    }

    /**
     * Get the number of words a string contains.
     *
     * @param  string|null  $characters
     * @return int
     */
    public function wordCount($characters = null)
    {
        return Str::wordCount($this->value, $characters);
    }

    /**
     * Wrap a string to a given number of characters.
     *
     * @param  int  $characters
     * @param  string  $break
     * @param  bool  $cutLongWords
     * @return static
     */
    public function wordWrap($characters = 75, $break = "\n", $cutLongWords = false)
    {
        return new static(Str::wordWrap($this->value, $characters, $break, $cutLongWords));
    }

    /**
     * Wrap the string with the given strings.
     *
     * @param  string  $before
     * @param  string|null  $after
     * @return static
     */
    public function wrap($before, $after = null)
    {
        return new static(Str::wrap($this->value, $before, $after));
    }

    /**
     * Dump the string.
     *
     * @return $this
     */
    public function dump()
    {
        echo "<pre>";
        print_r($this->value);
        echo "</pre>";

        return $this;
    }

    /**
     * Dump the string and end the script.
     *
     * @return never
     */
    public function dd()
    {
        $this->dump();

        exit(1);
    }

    /**
     * Get the underlying string value.
     *
     * @return string
     */
    public function value()
    {
        return $this->toString();
    }

    /**
     * Get the underlying string value.
     *
     * @return string
     */
    public function toString()
    {
        return $this->value;
    }

    /**
     * Get the underlying string value as an integer.
     *
     * @return int
     */
    public function toInteger()
    {
        return intval($this->value);
    }

    /**
     * Get the underlying string value as a float.
     *
     * @return float
     */
    public function toFloat()
    {
        return floatval($this->value);
    }

    /**
     * Get the underlying string value as a boolean.
     *
     * Returns true when value is "1", "true", "on", and "yes". Otherwise, returns false.
     *
     * @return bool
     */
    public function toBoolean()
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the underlying string value as a Carbon instance.
     *
     * @param  string|null  $format
     * @param  string|null  $tz
     * @return \FluentBooking\Framework\Support\DateTime
     *
     * @throws \InvalidArgumentException
     */
    public function toDate($format = null, $tz = null)
    {
        if (is_null($format)) {
            return DateTime::parse($this->value, $tz);
        }

        return DateTime::createFromFormat($format, $this->value, $tz);
    }

    /**
     * Convert the object to a string when JSON encoded.
     *
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->__toString();
    }

    /**
     * Proxy dynamic properties onto methods.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->{$key}();
    }

    /**
     * Get the raw string value.
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->value;
    }
}
