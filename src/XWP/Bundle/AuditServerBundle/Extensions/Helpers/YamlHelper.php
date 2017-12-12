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
    protected $literal_placeholder = '___YAML_Literal_Block___';

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
    protected $contains_group_alias = false;

    /**
     * Contains group anchor.
     *
     * @var bool
     */
    protected $_contains_group_anchor = false;

    /**
     * Path modifier that should be applied after adding current element.
     *
     * @var array
     */
    protected $delayed_path = array();

    /**
     * Saved groups.
     *
     * @var array
     */
    protected $saved_groups = array();

    /**
     * Get results from string.
     *
     * @param string $input Input yaml string.
     * @return array Results.
     */
    function load($input)
    {
        return $this->load_string($input);
    }

    /**
     * Get results from string.
     *
     * @param string $input Input string.
     * @return array Results.
     */
    protected function load_string($input)
    {
        $source = $this->load_from_string($input);
        return $this->load_with_source($source);
    }

    /**
     * Array of lines.
     *
     * @param string $input String input.
     * @return array Array of lines.
     */
    function load_from_string($input)
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
    protected function check_keys_in_value($value)
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
    public function return_key_value_pair($line)
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
                if (false === $this->check_keys_in_value($value)) {
                    return array();
                }
            }

            // Set the type of the value. Int, string, etc.
            $value = $this->to_type($value);
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
    protected function to_type($value)
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

            $explode = $this->inline_escape($inner_value);

            // Propagate value array.
            $value = array();
            foreach ($explode as $v) {
                $value[] = $this->to_type($v);
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
    protected function inline_escape($inline)
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
    function load_with_source($source)
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
            $tempPath = $this->get_parent_path_by_indent($this->indent);
            $line = $this->strip_indent($line, $this->indent);
            if ($this->is_comment($line)) {
                continue;
            }
            if ($this->is_empty($line)) {
                continue;
            }
            $this->path = $tempPath;

            $literal_block_style = $this->starts_literal_block($line);
            if ($literal_block_style) {
                $line = rtrim($line, $literal_block_style . " \n");
                $literal_block = '';
                $line .= $this->literal_placeholder;

                while (++$i < $cnt && $this->literal_block_continues($source[ $i ], $this->indent)) {
                    $literal_block = $this->add_literal_line($literal_block, $source[ $i ], $literal_block_style);
                }
                $i--;
            }

            while (++$i < $cnt && $this->greedily_need_next_line($line)) {
                $line = rtrim($line, " \n\t\r") . ' ' . ltrim($source[ $i ], " \t");
            }
            $i--;

            if (strpos($line, '#')) {
                if (false === strpos($line, '"') && false === strpos($line, "'")) {
                    $line = preg_replace('/\s+#(.+)$/', '', $line);
                }
            }

            $line_array = $this->parse_line($line);

            if ($literal_block_style) {
                if (! isset($literal_block)) {
                    $literal_block = '';
                }
                $line_array = $this->revert_literal_placeholder($line_array, $literal_block);
            }

            $this->add_array($line_array, $this->indent);

            foreach ($this->delayed_path as $indent => $delayed_path) {
                $this->path[ $indent ] = $delayed_path;
            }

            $this->delayed_path = array();
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
    function revert_literal_placeholder($line_array, $literal_block)
    {
        foreach ($line_array as $k => $_) {
            if (is_array($_)) {
                $line_array[ $k ] = $this->revert_literal_placeholder($_, $literal_block);
            } elseif (substr($_, -1 * strlen($this->literal_placeholder)) === $this->literal_placeholder) {
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
    protected static function strip_indent($line, $indent = -1)
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
    protected function get_parent_path_by_indent($indent)
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
    protected static function is_comment($line)
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
    protected static function is_empty($line)
    {
        return ( '' === trim($line) );
    }

    /**
     * If literal block starts.
     *
     * @param string $line Line.
     * @return bool|string If starts / last character.
     */
    protected static function starts_literal_block($line)
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
    protected function literal_block_continues($line, $line_indent)
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
    protected function add_literal_line($literal_block, $line, $literal_block_style, $indent = -1)
    {
        $line = self::strip_indent($line, $indent);
        if ('|' !== $literal_block_style) {
            $line = self::strip_indent($line);
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
    protected static function greedily_need_next_line($line)
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
    protected function add_array($incoming_data, $incoming_indent)
    {

        if (1 < count($incoming_data)) {
            return $this->add_array_inline($incoming_data, $incoming_indent);
        }

        $key = key($incoming_data);
        $value = isset($incoming_data[ $key ]) ? $incoming_data[ $key ] : null;
        if ('__!YAMLZero' === $key) {
            $key = '0';
        }

        // Shortcut for root-level values.
        if (0 === $incoming_indent && ! $this->contains_group_alias && ! $this->_contains_group_anchor) {
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

        if ($this->contains_group_alias) {
            $value = $this->reference_contents_by_alias($this->contains_group_alias);
            $this->contains_group_alias = false;
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

        if ($this->_contains_group_anchor) {
            $this->saved_groups[ $this->_contains_group_anchor ] = $this->path;
            if (is_array($value)) {
                $k = key($value);
                if (! is_int($k)) {
                    $this->saved_groups[ $this->_contains_group_anchor ][ $incoming_indent + 2 ] = $k;
                }
            }
            $this->_contains_group_anchor = false;
        }
    }

    /**
     * Reference contents by alias.
     *
     * @param string $alias Alias.
     * @return array|mixed
     */
    protected function reference_contents_by_alias($alias)
    {
        $value = '';
        do {
            if (! isset($this->saved_groups[ $alias ])) {
                echo "Bad group name: $alias.";
                break;
            }
            $groupPath = $this->saved_groups[ $alias ];
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
    protected function parse_line($line)
    {
        if (! $line) {
            return array();
        }
        $line = trim($line);
        if (! $line) {
            return array();
        }

        $group = $this->node_contains_group($line);
        if ($group) {
            $this->add_group($line, $group);
            $line = $this->strip_group($line, $group);
        }

        if ($this->starts_mapped_sequence($line)) {
            return $this->return_mapped_sequence($line);
        }

        if ($this->starts_mapped_value($line)) {
            return $this->return_mapped_value($line);
        }

        if ($this->is_array_element($line)) {
            return $this->return_array_element($line);
        }

        if ($this->is_plain_array($line)) {
            return $this->return_plain_array($line);
        }

        return $this->return_key_value_pair($line);
    }

    /**
     * Add array inline.
     *
     * @param array $array Array.
     * @param int   $indent Indent.
     * @return bool If add.
     */
    protected function add_array_inline($array, $indent)
    {
        $common_group_path = $this->path;
        if (empty($array)) {
            return false;
        }

        foreach ($array as $k => $_) {
            $this->add_array(array(
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
    protected function node_contains_group($line)
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
    protected function add_group($line, $group)
    {
        if ('&' === $group[0]) {
            $this->_contains_group_anchor = substr($group, 1);
        }
        if ('*' === $group[0]) {
            $this->contains_group_alias = substr($group, 1);
        }
    }

    /**
     * Strip group.
     *
     * @param string     $line Line.
     * @param array|bool $group Group.
     * @return string Line.
     */
    protected function strip_group($line, $group)
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
    protected function starts_mapped_sequence($line)
    {
        return ( '- ' === substr($line, 0, 2) && ':' === substr($line, -1, 1) );
    }

    /**
     * Return mapped sequence.
     *
     * @param string $line Line.
     * @return array Mapped sequence.
     */
    protected function return_mapped_sequence($line)
    {
        $array = array();
        $key = self::unquote(trim(substr($line, 1, -1)));
        $array[ $key ] = array();
        $this->delayed_path = array(
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
    protected function return_mapped_value($line)
    {
        $this->check_keys_in_value($line);
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
    protected function starts_mapped_value($line)
    {
        return ( ':' === substr($line, -1, 1) );
    }

    /**
     * If is array element.
     *
     * @param string $line Line.
     * @return bool If is array element.
     */
    protected function is_array_element($line)
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
    protected function return_array_element($line)
    {
        if (strlen($line) <= 1) {
            return array( array() );
        }
        $array = array();
        $value = trim(substr($line, 1));
        $value = $this->to_type($value);
        if ($this->is_array_element($value)) {
            $value = $this->return_array_element($value);
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
    protected function is_plain_array($line)
    {
        return ( '[' === $line[0] && ']' === substr($line, -1, 1) );
    }

    /**
     * Return plain array.
     *
     * @param string $line Line.
     * @return array Plain array.
     */
    protected function return_plain_array($line)
    {
        return $this->to_type($line);
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
