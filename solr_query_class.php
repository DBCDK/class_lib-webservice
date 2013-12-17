<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2013, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * Parse a cql-search and return the corresponding solr-search
 *
 */

require_once('tokenizer_class.php');
require_once('cql2rpn_class.php');

define('DEVELOP', FALSE);
define('TREE', $_REQUEST['tree']);


class SolrQuery extends tokenizer {

  var $dom;
  var $map;
  // full set of escapes as seen in the solr-doc. We use those who so far has been verified
  //var $solr_escapes = array('+','-','&&','||','!','(',')','{','}','[',']','^','"','~','*','?',':','\\');
  var $solr_escapes = array('+', '-', ':', '!', '"');
  var $solr_ignores = array();         // this should be kept empty
  var $solr_escapes_from = array();
  var $solr_escapes_to = array();
  var $phrase_index = array();
  var $best_match = FALSE;
  var $operator_translate = array();

  public function __construct($cql_xml, $config='', $language='') {
    $this->dom = new DomDocument();
    $this->dom->Load($cql_xml);

    $this->best_match = ($language == 'bestMatch');
    if ($language == 'cqldan') {
      $this->operator_translate = array('OG' => 'AND', 'ELLER' => 'OR', 'IKKE' => 'NOT');
    }
    $this->case_insensitive = TRUE;
    $this->split_expression = '/(<=|>=|[ <>=()[\]])/';
    $this->set_operators($language);
    $this->set_indexes_and_aliases();
    $this->ignore = array('/^prox\//');

    $this->interval = array('<' => '[* TO %s]', 
                            '<=' => '[* TO %s]', 
                            '>' => '[%s TO *]', 
                            '>=' => '[%s TO *]');
    $this->adjust_interval = array('<' => -1, '<=' => 0, '>' => 1, '>=' => 0);

    if ($config) {
      $this->phrase_index = $config->get_value('phrase_index', 'setup');
    }

    foreach ($this->solr_escapes as $ch) {
      $this->solr_escapes_from[] = $ch;
      $this->solr_escapes_to[] = '\\' . $ch;
    }
    foreach ($this->solr_ignores as $ch) {
      $this->solr_escapes_from[] = $ch;
      $this->solr_escapes_to[] = '';
    }
  }


  /** \brief Parse a cql-query and build the solr edismax search string
   * 
   */
  public function cql_2_edismax($query) {
    try {
      $tokens = $this->tokenize($query, $this->operator_translate);
      if (DEVELOP) { echo 'Query: ' . $query . PHP_EOL . print_r($tokens, TRUE) . PHP_EOL; }
      $rpn = Cql2Rpn::parse_tokens($tokens);
      $edismax = self::rpn_2_edismax($rpn);
    } catch (Exception $e) {
      $edismax = array('error' => $e->getMessage());
    }
    if (DEVELOP) print_r($edismax);
    //if (DEVELOP) die();
 
    if (TREE) {
      require_once('cql2tree_class.php');
      $parser = new cql_parser();
      //$parser->define_prefix('dkcclterm', 'DKCCLTERM', $dkcclterm_f_uri);
      $parser->parse($query);
      $tree = $parser->result();
      $diag = $parser->get_diagnostics();
      var_dump($tree);
      var_dump($diag);
      die();
    }
    return $edismax;
  }


  /** \brief build a boost string
   * @param boosts boost registers and values
   */
  public function make_boost($boosts) {
    if (is_array($boosts)) {
      foreach ($boosts as $idx => $val) {
        if ($idx && $val)
          $ret .= ($ret ? ' ' : '') . $idx . '^' . $val;
      }
    }
    return $ret;
  }

  // ------------------------- Private functions below -------------------------------------

  /** \brief Get list of registers and their types
   * 
   */
  private function set_indexes_and_aliases() {
    $idx = -1;
    $this->indexes = $this->aliases = array(); 
    foreach ($this->dom->getElementsByTagName('indexInfo') as $info_item) {
      foreach ($info_item->getElementsByTagName('index') as $index_item) {
        if ($map_item = $index_item->getElementsByTagName('map')->item(0)) {
          if (!self::xs_boolean($map_item->getAttribute('hidden')) && $name_item = $map_item->getElementsByTagName('name')->item(0)) {
            $this->indexes[++$idx] = $name_item->getAttribute('set').'.'.$name_item->nodeValue;
            foreach ($map_item->getElementsByTagName('alias') as $alias_item) {
              $this->aliases[$alias_item->nodeValue] = $this->indexes[$idx];
            }
          }
        }
      }
    }
  }

  private function xs_boolean($str) {
    return ($str == 1 || $str == 'true');
  }

  /** \brief Get list of valid operators
   * @param 
   */
  private function set_operators($language) {
    $this->operators = array(); 
    $boolean_lingo = ($language == 'cqldan' ? 'dan' : 'eng');
    foreach ($this->dom->getElementsByTagName('supports') as $support_item) {
      $type = $support_item->getAttribute('type');
      if (in_array($type, array('relation', 'booleanChar', $boolean_lingo . 'BooleanModifier'))) {
        $this->operators[] = $support_item->nodeValue;
      }
    }
  }

  /** \brief Makes an edismax query from the RPN-stack
   */
  private function rpn_2_edismax($rpn) {
    $ret = array();
    $folded_rpn = self::fold_operands($rpn);
    $ret['operands'] = 0;
    foreach ($folded_rpn as $r) {
      if ($r->type == OPERAND) {
        $ret['operands']++;
      }
    }
    $ret['edismax'] = self::folded_2_edismax($folded_rpn);

    if ($this->best_match) {
      $ret['best_match'] = self::remove_bool_and_expand_indexes($folded_rpn);
    }
    return $ret;
  }

  /** \brief folds OPERANDs bound to indexes depending on INDEX-type
   */
  private function fold_operands($rpn) {
    $intervals = array('<' => '[* TO %s]', 
                      '<=' => '[* TO %s]', 
                      '>' => '[%s TO *]', 
                      '>=' => '[%s TO *]');
    $adjust_intervals = array('<' => -1, '<=' => 0, '>' => 1, '>=' => 0);
    $curr_index = '';
    $edismax = '';
    $index_stack = array();
    $folded = array();
    $operand->type = OPERAND;
    if (DEVELOP) { echo 'fold_op: ' . print_r($rpn, TRUE) . PHP_EOL; }
    foreach ($rpn as $r) {
      if (DEVELOP) { echo $r->type . ' ' . $r->value . ' ' . print_r($operand, TRUE) . PHP_EOL; }
      switch ($r->type) {
        case INDEX:
          $curr_index = $r->value;
          break;
        case OPERAND:
          $r->value = str_replace($this->solr_escapes_from, $this->solr_escapes_to, $r->value);
          if ($curr_index) {
            $index_stack[] = $r;
          }
          else {
            $folded[] = $r;
          }
          break;
        case OPERATOR:
          switch ($r->value) {
            case '<':
            case '>':
            case '<=':
            case '>=':
              if (empty($curr_index)) {
                throw new Exception('CQL-4: Unknown register');
              }
              $interval = $intervals[$r->value];
              $interval_adjust = $adjust_intervals[$r->value];
              $imploded = self::implode_stack($index_stack);
              if (is_numeric($imploded)) {
                $operand->value = $curr_index . ':' . 
                                  sprintf($interval, intval($imploded) + $interval_adjust);
              }
              else {
                $o_len = strlen($imploded) - 1;
                $operand->value = $curr_index . ':' . 
                                  sprintf($interval, substr($imploded, 0, $o_len) . 
                                                     chr(ord(substr($imploded,$o_len)) + $interval_adjust));
              }
              if ($operand->value) {
                $folded[] = $operand;
              }
              $curr_index = '';
              $index_stack = array();
              break;
            case '=':
              if (empty($curr_index)) {
                throw new Exception('CQL-4: Unknown register');
              }
              $operand->value = self::implode_indexed_stack($index_stack, $curr_index);
              if (DEVELOP) { echo 'Imploded: ' . $operand->value . PHP_EOL; }
              if ($operand->value) {
                $folded[] = $operand;
              }
              $curr_index = '';
              $index_stack = array();
              break;
            case 'ADJ':
              if (empty($curr_index)) {
                throw new Exception('CQL-4: Unknown register');
              }
              $imploded = self::implode_stack($index_stack);
              $operand->value = $curr_index . ':"' . $imploded . '"~10';
              if ($operand->value) {
                $folded[] = $operand;
              }
              $curr_index = '';
              $index_stack = array();
              break;
            default:
              if ($curr_index) {
                $index_stack[] = $r;
              }
              else {
                if (isset($operand->value) && $operand->value) {
                  $folded[] = $operand;
                }
                $folded[] = $r;
              }
          }
          unset($operand);
          $operand->type = OPERAND;
          break;
        default:
          throw new Exception('CQL-5: Internal error: Unknown rpn-element-type');
      }
      if (DEVELOP && ($r->type == OPERATOR)) { echo 'folded: ' . print_r($folded, TRUE) . PHP_EOL; }
    }
    if (isset($operand->value) && $operand->value) {
      $folded[] = $operand;
    }
    if (DEVELOP) { echo 'rpn: ' . print_r($rpn, TRUE) . 'folded: ' . print_r($folded, TRUE); }
    return $folded;
  }


  /** \brief
   */
  private function remove_bool_and_expand_indexes($folded) {
    $ret = array();
    foreach ($folded as $f) {
      if ($f->type == OPERAND) {
        foreach (self::explode_indexes($f->value) as $t)
          $term[] = $t;
      }
    }
    $fraction = floor(100 / count($term));
    foreach ($term as $term_no => $t) {
      $n = 't' . $term_no;
      $ret[$n] = $t;
      $sort .= $split . 'query($' . $n . ',' . $fraction . ')';
      $split = ',';
    }
    $ret['sort'] = 'sum(' . $sort . ') asc';
    return $ret;
  }

  /** \brief explode 'index:"A B"' or index:(A B) to index:A and index:B
   */
  private function explode_indexes($term) {
    $parts = explode(':', $term, 2);
    if (count($parts) == 1) {
      $terms = array($term);
    }
    else {
      $term_list = preg_replace('/["\()]/', '', $parts[1]);
      foreach (explode(' ', $term_list) as $t) {
        $terms[] = $parts[0] . ':' . $t;
      }
    }
    return $terms;
  }

  /** \brief Unstacks and set solr-syntax depending on index-type
   */
  private function implode_indexed_stack($stack, $index, $adjacency = '') {
    list($idx_type) = explode('.', $index);
    if (in_array($idx_type, $this->phrase_index)) {
      return $index . ':"' . self::implode_stack($stack) . '"' . $adjacency;
    }
    elseif ($this->best_match) {
      return $index . ':(' . self::implode_stack($stack) . ')' . $adjacency;
    }
    else {
      return $index . ':(' . self::implode_stack($stack, 'AND') . ')' . $adjacency;
    }
  }

  /** \brief Unstacks and set/remove operator between operands
   */
  private function implode_stack($stack, $default_op = '') {
    $ret = '';
    $st = array();
    if ($default_op) {
      $default_op = ' ' . trim($default_op) . ' ';
    }
    else {
      $default_op = ' ';
    }
    foreach ($stack as $s) {
      if ($s->type == OPERATOR) {
        if ($s->value <> 'NO_OP') {
          $ret .= $st[count($st)-2] . ' ' . $s->value . ' ' . $st[count($st)-1];
          unset($st[count($st)-1]);
          unset($st[count($st)-1]);
        }
      }
      else {
        $st[count($st)] = $s->value;
      }
    }
    foreach ($st as $s) {
      $ret .= (!empty($ret) ? $default_op : '') . $s;
    }
    return $ret;
  }

  /** \brief Unstack folded stack and produce solr-search
   *         If bestMatch remove operators (for functionality) and parenthesis (for speed)
   */
  private function folded_2_edismax($folded) {
    if (DEVELOP) {
      for ($i = count($folded) - 1; $i; $i--) {
        echo $i . ' ' . $folded[$i]->value . PHP_EOL;
      }
    }
    $stack = $folded;
    $edismax = self::folded_unstack($stack);
    if (substr($edismax, 0, 1) == '(' && substr($edismax, -1) == ')') {
      $edismax = substr($edismax, 1, -1);
    }
    if (DEVELOP) { echo 'ed 22: ' . $edismax . PHP_EOL; }
    return $edismax;
  }

  private function folded_unstack(&$stack) {
    if ($stack) {
      $f = array_pop($stack);
      if ($f->type == OPERATOR) {
        $op = self::set_operator($f->value);
        if (DEVELOP) { echo 'operator at: ' . $pos . PHP_EOL; }
        $term1 = self::folded_unstack($stack);
        $term2 = self::folded_unstack($stack);
        return '(' . $term2 . ' ' . $op . ' ' . $term1 . ')';
      }
      else {
        if (DEVELOP) { echo 'other at: ' . $pos . PHP_EOL; }
        return $f->value;
      }
    }
  }

  private function set_operator($op) {
    if ($this->best_match) { return ''; }
    return ($op == 'NO_OP' ? 'AND' : $op);
  }

}


