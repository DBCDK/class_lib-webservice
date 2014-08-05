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

require_once('cql2tree_class.php');
//require_once('cql2rpn_class.php');

define('DEVELOP', FALSE);
define('TREE', $_REQUEST['tree']);


class SolrQuery {

  var $cql_dom;
  // full set of escapes as seen in the solr-doc. We use those who so far has been verified
  //var $solr_escapes = array('\\','+','-','&&','||','!','(',')','{','}','[',']','^','"','~','*','?',':');
  var $solr_escapes = array('\\', '+', '-', '!', '{', '}', '[', ']', '^', '"', '~', ':');
  var $solr_ignores = array();         // this should be kept empty
  var $phrase_index = array();
  var $best_match = FALSE;
  var $operator_translate = array();
  var $indexes = array();
  var $default_slop = 9999;
  var $cqlns = array();  // namespaces for the search-fields
  var $v2_v3 = array(    // translate v2 rec.id to v3 rec.id
      '150005' => '150005-artikel',
      '150008' => '150008-academic',
      '150012' => '150012-leksikon',
      '150014' => '150014-album',
      '150015' => '870970-basis',
      '150016' => '870971-forfweb',
      '150017' => '870971-faktalink',
      '150018' => '150018-danhist',
      '150021' => '150021-bibliotek',
      '150023' => '150023-sicref',
      '150025' => '150008-public',
      '150027' => '150021-fjern',
      '150028' => '870970-basis',
      '150030' => '870970-spilmedier',
      '150032' => '150018-samfund',
      '150033' => '150033-dandyr',
      '150034' => '150018-religion',
      '150039' => '150015-forlag',
      '150040' => '150033-verdyr',
      '150043' => '150043-atlas',
      '150048' => '870970-basis',
      '150052' => '870970-basis',
      '150054' => '150018-biologi',
      '150055' => '150018-fysikkemi',
      '150056' => '150018-geografi',
      '159002' => '159002-lokalbibl',
      '870971' => '870971-avis',
      '870973' => '870973-anmeld',
      '870976' => '870976-anmeld');

  public function __construct($cql_xml, $config='', $language='') {
    $this->cql_dom = new DomDocument();
    $this->cql_dom->Load($cql_xml);

    $this->best_match = ($language == 'bestMatch');
    // No boolean translate in strict cql
    //if ($language == 'cqldan') {
      //$this->operator_translate = array('og' => 'and', 'eller' => 'or', 'ikke' => 'not');
    //}
    // not strict cql  $this->set_operators($language);
    $this->set_cqlns();
    $this->set_indexes();
    //$this->ignore = array('/^prox\//');

    $this->interval = array('<' => '[* TO %s]', 
                            '<=' => '[* TO %s]', 
                            '>' => '[%s TO *]', 
                            '>=' => '[%s TO *]');
    $this->adjust_interval = array('<' => -1, '<=' => 0, '>' => 1, '>=' => 0);

    if ($config) {
      $this->phrase_index = $config->get_value('phrase_index', 'setup');
    }

  }


  /** \brief Parse a cql-query and build the solr edismax search string
   * 
   */
  public function parse($query) {
    $parser = new CQL_parser();
    $parser->set_prefix_namespaces($this->cqlns);
    $parser->set_indexes($this->indexes);
    // not strict cql $parser->set_boolean_translate($this->operator_translate);
    $parser->parse($query);
    $tree = $parser->result();
    $diags = $parser->get_diagnostics();
    if ($diags) {
      $ret['error'] = $diags;;
    }
    else {
      $trees = self::split_tree($tree);
      $ret['edismax'] = self::trees_2_edismax($trees);
      $ret['best_match'] = self::trees_2_bestmatch($trees);
      $ret['operands'] = self::trees_2_operands($trees);
      if (count($ret['operands']) && empty($ret['edismax']['q'])) {
        $ret['edismax']['q'][] = '*';
      }
    }
    return $ret;
  }


  /** \brief build a boost string
   * @param boosts (array) boost registers and values
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

  /** \brief convert all cql-trees to edismax-strings
   * @param trees (array) of trees
   */
  private function trees_2_edismax($trees) {
    $ret = array('q' => array(), 'fq' => array());
    foreach ($trees as $tree) {
      list($type, $edismax) = self::tree_2_edismax($tree);
      $ret[$type][] = $edismax;
    }
    return $ret;
  }


  /** \brief convert on cql-tree to edismax-string
   * @param trees (array) of trees
   */
  private function tree_2_edismax($node, $level = 0) {
    static $q_type;
    if ($level == 0) {
      $q_type = 'fq';
    }
    if ($node['type'] == 'boolean') {
      $ret = '(' . self::tree_2_edismax($node['left'], $level+1) . ' ' . strtoupper($node['op']) . ' ' . self::tree_2_edismax($node['right'], $level+1) . ')';
    }
    else {
      if (!self::is_filter_field($node['prefix'], $node['field'])) {
        $q_type = 'q';
      }
      $ret = self::make_solr_term($node['term'], $node['relation'], $node['prefix'], $node['field'], $node['slop']);
    }
    return ($level ? $ret : array($q_type, $ret));
  }

  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param trees (array) of trees
   */
  private function trees_2_bestmatch($trees) {
    foreach ($trees as $tree) {
      $ret[] = self::tree_2_bestmatch($tree);
    }
    $q = implode(' or ', $ret);
    $sort = self::make_bestmatch_sort($q);
    return array('q' => array($q), 'fq' => array(), 'sort' => $sort);
  }

  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param trees (array) of trees
   */
  private function tree_2_bestmatch($node, $level = 0) {
    if ($node['type'] == 'boolean') {
      $ret = self::tree_2_bestmatch($node['left'], $level+1) . ' or ' . self::tree_2_bestmatch($node['right'], $level+1);
    }
    else {
      $ret = self::make_bestmatch_term($node['term'], $node['relation'], $node['prefix'], $node['field'], $node['slop']);
    }
    return $ret;
  }

  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param trees (array) of trees
   */
  private function trees_2_operands($trees) {
    $ret = array();
    foreach ($trees as $tree) {
      $ret = array_merge($ret, self::tree_2_operands($tree));
    }
    return $ret;
  }
  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param trees (array) of trees
   */
  private function tree_2_operands($node, $level = 0) {
  static $ret;
    if ($node['type'] == 'boolean') {
      return array_merge(self::tree_2_operands($node['left']), self::tree_2_operands($node['right']));
    }
    else {
      return explode(' ', $node['term']);
    }
  }

  /** \brief builds: t1 = term1, t1 = term2 to be used as extra solr-parameters referenced fom the sort-parameter
   *         like "sum(query($t1, 25),query($t2,25),query($t3,25),query($t4,25)) asc" for 4 terms
   * @param query (string) 
   */
  private function make_bestmatch_sort($query) {
    $qs = explode(' or ', $query);
    $fraction = floor(100 / count($qs));
    foreach ($qs as $qi => $q) {
      $n = 't' . $qi;
      $ret[$n] = $q;
      $sort .= $comma . 'query($' . $n . ',' . $fraction . ')';
      $comma = ',';
    }
    $ret['sort'] = 'sum(' . $sort . ') asc';
    return $ret;
  }

  /** \brief create edismax term query for bestmatch
   * @param term (string)
   * @param relation (string)
   * @param prefix (string)
   * @param field (string)
   * @param slop (integer)
   */
  private function make_bestmatch_term($term, $relation, $prefix, $field, $slop) {
    $ret = array();
    $terms = explode(' ', $term);
    foreach ($terms as $t) {
      $ret[] = self::make_solr_term($t, $relation, $prefix, $field, $slop);
    }
    return implode(' or ', $ret);
  }

  /** \brief create edismax term query
   * @param term (string)
   * @param relation (string)
   * @param prefix (string)
   * @param field (string)
   * @param slop (integer)
   */
  private function make_solr_term($term, $relation, $prefix, $field, $slop) {
    $term = self::convert_old_recid($term, $prefix, $field);
    $quote = strpos($term, ' ') ? '"' : '';
    if ($field && ($field <> 'serverChoice')) {
      $m_field = ($prefix ? $prefix . '.' : '') . $field . ':';
      if (!$m_term = self::make_term_interval($term, $relation, $quote)) {
        if ($quote) {
          $m_slop = !in_array($prefix, $this->phrase_index) ? '~' . $slop : '';
        }
        $m_term = $quote . self::escape_solr_term($term) . $quote . $m_slop;
      }
    }
    else {
      $m_term = $quote . self::escape_solr_term($term) . $quote . ($quote ? '~' . $slop : '');
    }
    return  $m_field . $m_term;
  }

  /** \brief convert old rec.id's to version 3 rec.id's
   * @param term (string)
   * @param prefix (string)
   * @param field (string)
   */
  private function convert_old_recid($term, $prefix, $field) {
    if ($prefix == 'rec' && $field == 'id' && preg_match("/^([1-9][0-9]{5})(:.*)$/", $term, $match)) {
      return (array_key_exists($match[1], $this->v2_v3) ? $this->v2_v3[$match[1]] : '870970-basis') . $match[2];
    }
    else {
      return $term;
    }
  }

  /** \brief Escape character and remove characters to be ignored
   * @param field (string)
   */
  private function escape_solr_term($term) {
  static $solr_escapes_from;
  static $solr_escapes_to;
    if (!isset($solr_escapes_from)) {
      foreach ($this->solr_escapes as $ch) {
        $solr_escapes_from[] = $ch;
        $solr_escapes_to[] = '\\' . $ch;
      }
      foreach ($this->solr_ignores as $ch) {
        $solr_escapes_from[] = $ch;
        $solr_escapes_to[] = '';
      }
    }
    return str_replace($solr_escapes_from, $solr_escapes_to, $term);
  }

  /** \brief Detects fields which can go into solrs fq=
   * @param field (string)
   */
  private function make_term_interval($term, $relation, $quot) {
    if (($interval = $this->interval[$relation])) {
      $interval_adjust = $this->adjust_interval[$relation];
      if (is_numeric($term)) {
        return sprintf($interval, $quot . intval($term) + $interval_adjust . $quot);
      }
      else {
        $o_len = strlen($term) - 1;
        return sprintf($interval, $quot . substr($term, 0, $o_len) .  chr(ord(substr($term,$o_len)) + $interval_adjust) . $quot);
      }
    }
    return NULL;
  }

  /** \brief Detects fields which can go into solrs fq=
   * @param field (string)
   */
  private function is_filter_field($prefix, $field) {
    return $this->indexes[$field][$prefix]['filter'];
  }


  /** \brief Split cql tree into several and-trees
   * @param cql tree
   * @return array of tree
   */
  private function split_tree($tree) {
    if ($tree['type'] == 'boolean' && strtolower($tree['op']) == 'and') {
      return array_merge(self::split_tree($tree['left']), self::split_tree($tree['right']));
    }
    else {
      return array($tree);
    }
    
  }

  private function set_cqlns() {
    foreach ($this->cql_dom->getElementsByTagName('indexInfo') as $info_item) {
      foreach ($info_item->getElementsByTagName('set') as $set) {
        $this->cqlns[$set->getAttribute('name')] = $set->getAttribute('identifier');
      }
    }
  }
  private function set_indexes() {
    foreach ($this->cql_dom->getElementsByTagName('indexInfo') as $info_item) {
      foreach ($info_item->getElementsByTagName('index') as $index_item) {
        if ($map_item = $index_item->getElementsByTagName('map')->item(0)) {
          if (!self::xs_boolean($map_item->getAttribute('hidden')) && $name_item = $map_item->getElementsByTagName('name')->item(0)) {
            $filter = self::xs_boolean($name_item->getAttribute('filter'));
            if (NULL == ($slop = $name_item->getAttribute('slop'))) {
              $slop = $this->default_slop;
            }
            if (NULL == ($handler = $name_item->getAttribute('searchHandler'))) {
              $handler = 'edismax';
            }
            $this->indexes[$name_item->nodeValue][$name_item->getAttribute('set')] = array('filter' => $filter, 
                                                                                           'slop' => $slop, 
                                                                                           'handler' => $handler);
            foreach ($map_item->getElementsByTagName('alias') as $alias_item) {
              if (NULL == ($slop = $alias_item->getAttribute('slop'))) {
                $slop = $this->default_slop;
              }
              $this->indexes[$alias_item->nodeValue][$alias_item->getAttribute('set')]['alias'] = 
                     array('slop' => $slop,
                           'handler' => $handler,
                           'prefix' => $name_item->getAttribute('set'), 
                           'field' => $name_item->nodeValue);
            }
          }
        }
      }
    }
  }

  /** \brief Get list of valid operators
   * @param str (string)
   */
  private function xs_boolean($str) {
    return ($str == 1 || $str == 'true');
  }

  /** \brief Get list of valid operators
   * @param language (string)
   */
/* not used any more - operators are given in cql 
  private function set_operators($language) {
    $this->operators = array(); 
    $boolean_lingo = ($language == 'cqldan' ? 'dan' : 'eng');
    foreach ($this->cql_dom->getElementsByTagName('supports') as $support_item) {
      $type = $support_item->getAttribute('type');
      if (in_array($type, array('relation', 'booleanChar', $boolean_lingo . 'BooleanModifier'))) {
        $this->operators[] = $support_item->nodeValue;
      }
    }
  }
*/

}

