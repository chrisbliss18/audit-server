<?php
/**
 * YAML Helper class for parsing YAML.
 * This file is based on https://github.com/mustangostang/spyc/blob/master/Spyc.php
 * and is using only the necessary methods and adjusting those as necessary.
 *
 * @package XWP\Bundle\AuditServerBundle\Extensions\Helpers
 */

namespace XWP\Bundle\AuditServerBundle\Extensions\Helpers;

/**
 * Class YamlHelper
 *
 * @package XWP\Bundle\AuditServerBundle\Extensions\Helpers
 */
class YamlHelper
{

    /**
     * Path.
     *
     * @var array
     */
    protected $path;

    /**
     * Result.
     *
     * @var array
     */
    protected $result;

    /**
     * Placeholder.
     *
     * @var string
     */
    protected $literalPlaceholder = '___YAML_Literal_Block___';

    /**
     * Indent.
     *
     * @var int
     */
    protected $indent;

    /**
     * Contains group alias.
     *
     * @var bool
     */
    protected $containsGroupAlias = false;

    /**
     * Contains group anchor.
     *
     * @var bool
     */
    protected $containsGroupAnchor = false;

    /**
     * Path modifier that should be applied after adding current element.
     *
     * @var array
     */
    protected $delayedPath = array();

    /**
     * Saved groups.
     *
     * @var array
     */
    protected $savedGroups = array();

    /**
     * Get results from string.
     *
     * @param string $input Input yaml string.
     * @return array Results.
     */
    public function load($input)
    {
        return $this->loadString($input);
    }

    /**
     * Get results from string.
     *
     * @param string $input Input string.
     * @return array Results.
     */
    protected function loadString($input)
    {
        $source = $this->loadFromString($input);
        return $this->loadWithSource($source);
    }

    /**
     * Array of lines.
     *
     * @param string $input String input.
     * @return array Array of lines.
     */
    protected function loadFromString($input)
    {
        $lines = explode("\n", $input);
        foreach ($lines as $k => $_) {
            $lines[ $k ] = rtrim($_, "\r");
        }
        return $lines;
    }

    /**
     * Check keys in value.
     *
     * @param string $value Value.
     * @return bool False if incorrect.
     */
    protected function checkKeysInValue($value)
    {
        if (false === strchr('[{"\'', $value[0])) {
            if (false !== strchr($value, ': ')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return key-value pair from one line.
     *
     * @param string $line Line.
     * @return array Pair.
     */
    public function returnKeyValuePair($line)
    {
        $array = array();
        if (strpos($line, ': ')) {
            /*
			 * It's a key/value pair most likely.
			 * If the key is in double quotes pull it out.
			 */
            if (( '"' === $line[0] || "'" === $line[0] ) && preg_match('/^(["\'](.*)["\'](\s)*:)/', $line, $matches)) {
                $value = trim(str_replace($matches[1], '', $line));
                $key = $matches[2];
            } else {
                // Do some guesswork as to the key and the value.
                $explode = explode(': ', $line);
                $key = trim(array_shift($explode));
                $value = trim(implode(': ', $explode));
                if (false === $this->checkKeysInValue($value)) {
                    return array();
                }
            }

            // Set the type of the value. Int, string, etc.
            $value = $this->toType($value);
            if ('0' === $key) {
                $key = '__!YAMLZero';
            }
            $array[ $key ] = $value;
        } else {
            $array = array( $line );
        }
        return $array;
    }

    /**
     * Finds the type of the passed value, returns the value as the new type.
     *
     * @param string $value Value.
     * @return mixed
     */
    protected function toType($value)
    {
        if ('' === $value) {
            return $value;
        }
        $first_character = $value[0];
        $last_character = substr($value, -1, 1);

        $is_quoted = false;
        do {
            if (! $value) {
                break;
            }
            if ('"' !== $first_character && "'" !== $first_character) {
                break;
            }
            if ('"' !== $last_character && "'" !== $last_character) {
                break;
            }
            $is_quoted = true;
        } while (0);

        if ($is_quoted) {
            $value = str_replace('\n', "\n", $value);
            if ("'" === $first_character) {
                return strtr(substr($value, 1, -1), array(
                    '\'\'' => '\'',
                    '\\\'' => '\'',
                ));
            }
            return strtr(substr($value, 1, -1), array(
                '\\"'  => '"',
                '\\\'' => '\'',
            ));
        }

        if (false !== strpos($value, ' #') && ! $is_quoted) {
            $value = preg_replace('/\s+#(.+)$/', '', $value);
        }

        if ('[' === $first_character && ']' === $last_character) {
            // Take out strings sequences and mappings.
            $inner_value = trim(substr($value, 1, -1));
            if ('' === $inner_value) {
                return array();
            }

            $explode = $this->inlineEscape($inner_value);

            // Propagate value array.
            $value = array();
            foreach ($explode as $v) {
                $value[] = $this->toType($v);
            }
        }
        return $value;
    }


    /**
     * Used in inlines to check for more inlines or quoted strings
     *
     * @access protected
     * @param string $inline Inline.
     * @return array Array.
     */
    protected function inlineEscape($inline)
    {

        $seqs = array();
        $maps = array();
        $saved_strings = array();
        $saved_empties = array();

        // Check for empty strings.
        $regex = '/("")|(\'\')/';
        if (preg_match_all($regex, $inline, $strings)) {
            $saved_empties = $strings[0];
            $inline  = preg_replace($regex, 'YAMLEmpty', $inline);
        }
        unset($regex);

        // Check for strings.
        $regex = '/(?:(")|(?:\') )((?(1)[^"]+|[^\']+) )(?(1)"|\')/';
        if (preg_match_all($regex, $inline, $strings)) {
            $saved_strings = $strings[0];
            $inline = preg_replace($regex, 'YAMLString', $inline);
        }
        unset($regex);

        $i = 0;
        do {
            // Check for sequences.
            while (preg_match('/\[([^{}\[\]]+)\]/U', $inline, $matchseqs)) {
                $seqs[] = $matchseqs[0];
                $inline = preg_replace('/\[([^{}\[\]]+)\]/U', ( 'YAMLSeq' . ( count($seqs) - 1 ) . 's' ), $inline, 1);
            }

            // Check for mappings.
            while (preg_match('/{([^\[\]{}]+)}/U', $inline, $matchmaps)) {
                $maps[] = $matchmaps[0];
                $inline = preg_replace('/{([^\[\]{}]+)}/U', ( 'YAMLMap' . ( count($maps) - 1 ) . 's' ), $inline, 1);
            }

            if (10 <= $i++) {
                break;
            }
        } while (false !== strpos($inline, '[') || false !== strpos($inline, '{'));

        $explode = explode(',', $inline);
        $explode = array_map('trim', $explode);
        $stringi = 0;
        $i = 0;

        while (1) {
            // Re-add the sequences.
            if (! empty($seqs)) {
                foreach ($explode as $key => $value) {
                    if (false !== strpos($value, 'YAMLSeq')) {
                        foreach ($seqs as $seqk => $seq) {
                            $explode[ $key ] = str_replace(( 'YAMLSeq' . $seqk . 's' ), $seq, $value);
                            $value = $explode[ $key ];
                        }
                    }
                }
            }

            // Re-add the mappings.
            if (! empty($maps)) {
                foreach ($explode as $key => $value) {
                    if (false !== strpos($value, 'YAMLMap')) {
                        foreach ($maps as $mapk => $map) {
                            $explode[ $key ] = str_replace(( 'YAMLMap' . $mapk . 's' ), $map, $value);
                            $value = $explode[ $key ];
                        }
                    }
                }
            }

            // Re-add the strings.
            if (! empty($saved_strings)) {
                foreach ($explode as $key => $value) {
                    while (false !== strpos($value, 'YAMLString')) {
                        $explode[ $key ] = preg_replace('/YAMLString/', $saved_strings[ $stringi ], $value, 1);
                        unset($saved_strings[ $stringi ]);
                        ++$stringi;
                        $value = $explode[ $key ];
                    }
                }
            }

            // Re-add the empties.
            if (! empty($saved_empties)) {
                foreach ($explode as $key => $value) {
                    while (false !== strpos($value, 'YAMLEmpty')) {
                        $explode[ $key ] = preg_replace('/YAMLEmpty/', '', $value, 1);
                        $value = $explode[ $key ];
                    }
                }
            }

            $finished = true;
            foreach ($explode as $key => $value) {
                if (false !== strpos($value, 'YAMLSeq')) {
                    $finished = false;
                    break;
                }
                if (false !== strpos($value, 'YAMLMap')) {
                    $finished = false;
                    break;
                }
                if (false !== strpos($value, 'YAMLString')) {
                    $finished = false;
                    break;
                }
                if (false !== strpos($value, 'YAMLEmpty')) {
                    $finished = false;
                    break;
                }
            }
            if ($finished) {
                break;
            }

            $i++;
            if ($i > 10) {
                break;
            } // Prevent infinite loops.
        }

        return $explode;
    }

    /**
     * Load with source.
     *
     * @param array $source Source.
     * @return array Result.
     */
    protected function loadWithSource($source)
    {
        if (empty($source)) {
            return array();
        }

        $this->path = array();
        $this->result = array();

        $cnt = count($source);
        for ($i = 0; $i < $cnt; $i++) {
            $line = $source[ $i ];

            $this->indent = strlen($line) - strlen(ltrim($line));
            $tempPath = $this->getParentPathByIndent($this->indent);
            $line = $this->stripIndent($line, $this->indent);
            if ($this->isComment($line)) {
                continue;
            }
            if ($this->isEmpty($line)) {
                continue;
            }
            $this->path = $tempPath;

            $literal_block_style = $this->startsLiteralBlock($line);
            if ($literal_block_style) {
                $line = rtrim($line, $literal_block_style . " \n");
                $literal_block = '';
                $line .= $this->literalPlaceholder;

                while (++$i < $cnt && $this->literalBlockContinues($source[ $i ], $this->indent)) {
                    $literal_block = $this->addLiteralLine($literal_block, $source[ $i ], $literal_block_style);
                }
                $i--;
            }

            while (++$i < $cnt && $this->greedilyNeedNextLine($line)) {
                $line = rtrim($line, " \n\t\r") . ' ' . ltrim($source[ $i ], " \t");
            }
            $i--;

            if (strpos($line, '#')) {
                if (false === strpos($line, '"') && false === strpos($line, "'")) {
                    $line = preg_replace('/\s+#(.+)$/', '', $line);
                }
            }

            $line_array = $this->parseLine($line);

            if ($literal_block_style) {
                if (! isset($literal_block)) {
                    $literal_block = '';
                }
                $line_array = $this->revertLiteralPlaceholder($line_array, $literal_block);
            }

            $this->addArray($line_array, $this->indent);

            foreach ($this->delayedPath as $indent => $delayed_path) {
                $this->path[ $indent ] = $delayed_path;
            }

            $this->delayedPath = array();
        }
        return $this->result;
    }

    /**
     * Revert literal placeholder.
     *
     * @param array  $line_array Line array.
     * @param string $literal_block Literal block.
     * @return array Reversed line array.
     */
    protected function revertLiteralPlaceholder($line_array, $literal_block)
    {
        foreach ($line_array as $k => $_) {
            if (is_array($_)) {
                $line_array[ $k ] = $this->revertLiteralPlaceholder($_, $literal_block);
            } elseif (substr($_, -1 * strlen($this->literalPlaceholder)) === $this->literalPlaceholder) {
                $line_array[ $k ] = rtrim($literal_block, " \r\n");
            }
        }
        return $line_array;
    }

    /**
     * Strip indent.
     *
     * @param string $line Line.
     * @param int    $indent Indent.
     * @return string Stripped line.
     */
    protected static function stripIndent($line, $indent = -1)
    {
        if (-1 === $indent) {
            $indent = strlen($line) - strlen(ltrim($line));
        }
        return substr($line, $indent);
    }

    /**
     * Get parent path by indent.
     *
     * @param int $indent Indent.
     * @return array|string Parent path.
     */
    protected function getParentPathByIndent($indent)
    {
        if (0 === $indent) {
            return array();
        }
        $linePath = $this->path;
        do {
            end($linePath);
            $last_indent_in_parent_path = key($linePath);
            if ($indent <= $last_indent_in_parent_path) {
                array_pop($linePath);
            }
        } while ($indent <= $last_indent_in_parent_path);
        return $linePath;
    }

    /**
     * If is comment.
     *
     * @param string $line Line.
     * @return bool If is comment.
     */
    protected static function isComment($line)
    {
        if (! $line) {
            return false;
        }
        if ('#' === $line[0]) {
            return true;
        }
        if ('---' === trim($line, " \r\n\t")) {
            return true;
        }
        return false;
    }

    /**
     * If is empty.
     *
     * @param string $line Line.
     * @return bool If is empty line.
     */
    protected static function isEmpty($line)
    {
        return ( '' === trim($line) );
    }

    /**
     * If literal block starts.
     *
     * @param string $line Line.
     * @return bool|string If starts / last character.
     */
    protected static function startsLiteralBlock($line)
    {
        $last_char = substr(trim($line), -1);
        if ('>' !== $last_char && '|' !== $last_char) {
            return false;
        }
        if ('|' == $last_char) {
            return $last_char;
        }

        // HTML tags should not be counted as literal blocks.
        if (preg_match('#<.*?>$#', $line)) {
            return false;
        }
        return $last_char;
    }

    /**
     * If literal block continues.
     *
     * @param string $line Line.
     * @param int    $line_indent Indent.
     * @return bool True/false.
     */
    protected function literalBlockContinues($line, $line_indent)
    {
        if (! trim($line)) {
            return true;
        }
        if (strlen($line) - strlen(ltrim($line)) > $line_indent) {
            return true;
        }
        return false;
    }

    /**
     * Add literal line.
     *
     * @param string $literal_block Literal block.
     * @param string $line Line.
     * @param string $literal_block_style Literal block style.
     * @param int    $indent Indent.
     * @return string Line.
     */
    protected function addLiteralLine($literal_block, $line, $literal_block_style, $indent = -1)
    {
        $line = self::stripIndent($line, $indent);
        if ('|' !== $literal_block_style) {
            $line = self::stripIndent($line);
        }
        $line = rtrim($line, "\r\n\t ") . "\n";
        if ('|' === $literal_block_style) {
            return $literal_block . $line;
        }
        if (0 === strlen($line)) {
            return rtrim($literal_block, ' ') . "\n";
        }
        if ("\n" === $line && '>' === $literal_block_style) {
            return rtrim($literal_block, " \t") . "\n";
        }
        if ("\n" !== $line) {
            $line = trim($line, "\r\n ") . ' ';
        }
        return $literal_block . $line;
    }

    /**
     * If need next line.
     *
     * @param string $line Line.
     * @return bool True/false.
     */
    protected static function greedilyNeedNextLine($line)
    {
        $line = trim($line);
        if (! strlen($line)) {
            return false;
        }
        if (']' === substr($line, -1, 1)) {
            return false;
        }
        if ('[' === $line[0]) {
            return true;
        }
        if (preg_match('#^[^:]+?:\s*\[#', $line)) {
            return true;
        }
        return false;
    }

    /**
     * Add array.
     *
     * @param array $incoming_data Incoming data.
     * @param int   $incoming_indent Incoming indent.
     * @return bool|void
     */
    protected function addArray($incoming_data, $incoming_indent)
    {

        if (1 < count($incoming_data)) {
            return $this->addArrayInline($incoming_data, $incoming_indent);
        }

        $key = key($incoming_data);
        $value = isset($incoming_data[ $key ]) ? $incoming_data[ $key ] : null;
        if ('__!YAMLZero' === $key) {
            $key = '0';
        }

        // Shortcut for root-level values.
        if (0 === $incoming_indent && ! $this->containsGroupAlias && ! $this->containsGroupAnchor) {
            if ($key || '' === $key || '0' === $key) {
                $this->result[ $key ] = $value;
            } else {
                $this->result[] = $value;
                end($this->result);
                $key = key($this->result);
            }
            $this->path[ $incoming_indent ] = $key;
            return;
        }

        $history = array();

        // Unfolding inner array tree.
        $_arr = $this->result;
        $history[] = $this->result;
        foreach ($this->path as $k) {
            $_arr = $_arr[ $k ];
            $history[] = $_arr;
        }

        if ($this->containsGroupAlias) {
            $value                    = $this->referenceContentsByAlias($this->containsGroupAlias);
            $this->containsGroupAlias = false;
        }

        // Adding string or numeric key to the innermost level or $this->arr.
        if (is_string($key) && '<<' === $key) {
            if (! is_array($_arr)) {
                $_arr = array();
            }

            $_arr = array_merge($_arr, $value);
        } elseif ($key || '' === $key || '0' === $key) {
            if (! is_array($_arr)) {
                $_arr = array(
                    $key => $value,
                );
            } else {
                $_arr[ $key ] = $value;
            }
        } else {
            if (! is_array($_arr)) {
                $_arr = array( $value );
                $key = 0;
            } else {
                $_arr[] = $value;
                end($_arr);
                $key = key($_arr);
            }
        }

        $reverse_path = array_reverse($this->path);
        $reverse_history = array_reverse($history);
        $reverse_history[0] = $_arr;
        $cnt = count($reverse_history) - 1;
        for ($i = 0; $i < $cnt; $i++) {
            $reverse_history[ $i + 1 ][ $reverse_path[ $i ] ] = $reverse_history[ $i ];
        }
        $this->result = $reverse_history[ $cnt ];

        $this->path[ $incoming_indent ] = $key;

        if ($this->containsGroupAnchor) {
            $this->savedGroups[ $this->containsGroupAnchor ] = $this->path;
            if (is_array($value)) {
                $k = key($value);
                if (! is_int($k)) {
                    $this->savedGroups[ $this->containsGroupAnchor ][ $incoming_indent + 2 ] = $k;
                }
            }
            $this->containsGroupAnchor = false;
        }
    }

    /**
     * Reference contents by alias.
     *
     * @param string $alias Alias.
     * @return array|mixed
     */
    protected function referenceContentsByAlias($alias)
    {
        $value = '';
        do {
            if (! isset($this->savedGroups[ $alias ])) {
                echo "Bad group name: $alias.";
                break;
            }
            $groupPath = $this->savedGroups[ $alias ];
            $value = $this->result;
            foreach ($groupPath as $k) {
                $value = $value[ $k ];
            }
        } while (false);
        return $value;
    }


    /**
     * Parses YAML code and returns an array for a node
     *
     * @access protected
     * @return array Array.
     * @param string $line A line from the YAML file.
     */
    protected function parseLine($line)
    {
        if (! $line) {
            return array();
        }
        $line = trim($line);
        if (! $line) {
            return array();
        }

        $group = $this->nodeContainsGroup($line);
        if ($group) {
            $this->addGroup($line, $group);
            $line = $this->stripGroup($line, $group);
        }

        if ($this->startsMappedSequence($line)) {
            return $this->returnMappedSequence($line);
        }

        if ($this->startsMappedValue($line)) {
            return $this->returnMappedValue($line);
        }

        if ($this->isArrayElement($line)) {
            return $this->returnArrayElement($line);
        }

        if ($this->isPlainArray($line)) {
            return $this->returnPlainArray($line);
        }

        return $this->returnKeyValuePair($line);
    }

    /**
     * Add array inline.
     *
     * @param array $array Array.
     * @param int   $indent Indent.
     * @return bool If add.
     */
    protected function addArrayInline($array, $indent)
    {
        $common_group_path = $this->path;
        if (empty($array)) {
            return false;
        }

        foreach ($array as $k => $_) {
            $this->addArray(array(
                $k => $_,
            ), $indent);
            $this->path = $common_group_path;
        }
        return true;
    }

    /**
     * If node contains group.
     *
     * @param string $line Line.
     * @return bool If contains.
     */
    protected function nodeContainsGroup($line)
    {
        $symbols_for_reference = 'A-z0-9_\-';
        if (false === strpos($line, '&') && false === strpos($line, '*')) {
            return false;
        }
        if ('&' === $line[0] && preg_match('/^(&[' . $symbols_for_reference . ']+)/', $line, $matches)) {
            return $matches[1];
        }
        if ('*' === $line[0] && preg_match('/^(\*[' . $symbols_for_reference . ']+)/', $line, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(&[' . $symbols_for_reference . ']+)$/', $line, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\*[' . $symbols_for_reference . ']+$)/', $line, $matches)) {
            return $matches[1];
        }
        if (preg_match('#^\s*<<\s*:\s*(\*[^\s]+).*$#', $line, $matches)) {
            return $matches[1];
        }
        return false;
    }

    /**
     * Add group.
     *
     * @param string     $line Line.
     * @param array|bool $group Group.
     */
    protected function addGroup($line, $group)
    {
        if ('&' === $group[0]) {
            $this->containsGroupAnchor = substr($group, 1);
        }
        if ('*' === $group[0]) {
            $this->containsGroupAlias = substr($group, 1);
        }
    }

    /**
     * Strip group.
     *
     * @param string     $line Line.
     * @param array|bool $group Group.
     * @return string Line.
     */
    protected function stripGroup($line, $group)
    {
        $line = trim(str_replace($group, '', $line));
        return $line;
    }

    /**
     * If starts mapped sequence.
     *
     * @param string $line Line.
     * @return bool If starts mapped sequence.
     */
    protected function startsMappedSequence($line)
    {
        return ( '- ' === substr($line, 0, 2) && ':' === substr($line, -1, 1) );
    }

    /**
     * Return mapped sequence.
     *
     * @param string $line Line.
     * @return array Mapped sequence.
     */
    protected function returnMappedSequence($line)
    {
        $array             = array();
        $key               = self::unquote(trim(substr($line, 1, -1)));
        $array[ $key ]     = array();
        $this->delayedPath = array(
            strpos($line, $key) + $this->indent => $key,
        );
        return array( $array );
    }

    /**
     * Return mapped value of a line.
     *
     * @param string $line Line.
     * @return array Mapped value.
     */
    protected function returnMappedValue($line)
    {
        $this->checkKeysInValue($line);
        $array = array();
        $key = self::unquote(trim(substr($line, 0, -1)));
        $array[ $key ] = '';
        return $array;
    }

    /**
     * If starts mapped value.
     *
     * @param string $line Line.
     * @return bool If starts mapped value.
     */
    protected function startsMappedValue($line)
    {
        return ( ':' === substr($line, -1, 1) );
    }

    /**
     * If is array element.
     *
     * @param string $line Line.
     * @return bool If is array element.
     */
    protected function isArrayElement($line)
    {
        if (! $line || ! is_scalar($line)) {
            return false;
        }
        if ('- ' !== substr($line, 0, 2)) {
            return false;
        }
        if (strlen($line) > 3) {
            if ('---' === substr($line, 0, 3)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return array element.
     *
     * @param string $line Line.
     * @return array Array element.
     */
    protected function returnArrayElement($line)
    {
        if (strlen($line) <= 1) {
            return array( array() );
        }
        $array = array();
        $value = trim(substr($line, 1));
        $value = $this->toType($value);
        if ($this->isArrayElement($value)) {
            $value = $this->returnArrayElement($value);
        }
        $array[] = $value;
        return $array;
    }

    /**
     * If is plain array.
     *
     * @param string $line Line.
     * @return bool If is plain array.
     */
    protected function isPlainArray($line)
    {
        return ( '[' === $line[0] && ']' === substr($line, -1, 1) );
    }

    /**
     * Return plain array.
     *
     * @param string $line Line.
     * @return array Plain array.
     */
    protected function returnPlainArray($line)
    {
        return $this->toType($line);
    }

    /**
     * Remove quotes.
     *
     * @param string $value String to change.
     * @return string Modified string.
     */
    protected static function unquote($value)
    {
        if (! $value) {
            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        if ('\'' === $value[0]) {
            return trim($value, '\'');
        }
        if ('"' === $value[0]) {
            return trim($value, '"');
        }
        return $value;
    }
}
