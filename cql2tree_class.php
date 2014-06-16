<?php
/*
   Copyright (C) 2004 Index Data Aps, www.indexdata.dk

   This file is part of SRW/PHP

   This program is free software; you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation; version 2 dated June, 1991.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   A copy of the GNU General Public License is also available at
   <URL:http://www.gnu.org/copyleft/gpl.html>.  You may also obtain
   it by writing to the Free Software Foundation, Inc., 59 Temple
   Place - Suite 330, Boston, MA 02111-1307, USA.


   Parts Copyright © 2014 Dansk Bibliotekscenter a/s, www.dbc.dk

   This file is part of Open Library System.
   Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
  
   Open Library System is free software: you can redistribute it and/or modify
   it under the terms of the GNU Affero General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.
  
   Open Library System is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU Affero General Public License for more details.
  
   You should have received a copy of the GNU Affero General Public License
   along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * \brief Class for parsing a CQL query to the corresponding query tree
 *
 * CQL: http://www.loc.gov/standards/sru/cql/contextSets/theCqlContextSet.html
 *
 */

class CQL_parser {
  private $qs; // query string
  private $ql; // query string length
  private $qi; // position in qs when parsing
  private $look; // last seen token
  private $val; // attribute value for token
  private $lval; // lower case of value when string
  private $tree = array();
  private $std_prefixes = array();
  private $indexes = array();
  private $diags = ''; // diagnostics array to be passed to SRW-response
  private $booleans = array('and', 'or', 'not', 'prox');
  private $unsupported_booleans = array('prox');
  private $boolean_translate = array();
  private $defined_relations = array('adj', 'all', 'any', 'encloses', 'within');
  private $implicit_relations = array('=', '==', '<>', '<', '>', '<=', '>=');
  private $unsupported_relations = array('==', '<>', 'any', 'encloses', 'within');
  private $supported_modifiers = array(  // prox is not supported, but modifiers could be defines as this
              'prox' => array(
                 'unit' => array('symbol' => '/^=$/', 'unit' => '/word/', 'error' => 42), 
                 'distance' => array('symbol' => '/^=$/', 'unit' => '/^\d*$/', 'error' => 41)));
  private $parse_ok = TRUE; // cql parsing went ok
  private $diagnostics; 

  public function __construct() { }
  
  /** \brief for supporting national versions of and, or, not - will violate strict cql
   * @param boolean (array)
   **/
  public function set_boolean_translate($booleans) {
    if (is_array($booleans)) {
      foreach ($booleans as $alt => $bool) {
        if (in_array($bool, $this->booleans)) {
          $this->boolean_translate[$alt] = $bool;
          $this->booleans[] = $alt;
        }
      }
    }
  }

  /** \brief 
   * @param namespaces (array)
   **/
  public function set_prefix_namespaces($namespaces) {
    if (is_array($namespaces)) {
      foreach ($namespaces as $prefix => $uri) {
        self::define_prefix($prefix, $prefix, $uri);
      }
    }
  }

  /** \brief 
   * @param prefix (string)
   * @param title (string)
   * @param uri (string)
   **/
  public function define_prefix($prefix, $title, $uri) {
    $this->std_prefixes = self::add_prefix($this->std_prefixes, $prefix, $title, $uri);
  }
  
  /** \brief 
   * @param query (string)
   **/
  public function set_indexes($indexes) {
    $this->indexes = $indexes;
  }

  /** \brief 
   * @param query (string)
   **/
  public function parse($query) {
    $this->qs = $query;
    $this->ql = strlen($query);
    $this->qi = 0;
    $this->look = TRUE;
    self::move();
    $this->tree = self::cqlQuery('cql.serverChoice', 'scr', $this->std_prefixes, array());
    if ($this->look != FALSE) 
      self::add_diagnostic(10, "$this->qi");
    
    return $this->parse_ok;
  }
  
  /** \brief 
   * 
   **/
  public function result() {
    return $this->tree;
  }
  
  /** \brief 
   * @param query (string)
   **/
  public function result2xml($ar) {
    return self::tree2xml_r($ar, 0);
  }
  
  /** \brief 
   * 
   **/
  public function get_diagnostics() {
    return $this->diagnostics;
  }

  /* -------------------------------------------------------------------------------- */

  /** \brief get next token by setting class vars qi, look, val and lval
   * 
   **/
  private function move() {
    while ($this->qi < $this->ql && strchr(" \t\r\n", $this->qs[$this->qi])) 
      $this->qi++;
    if ($this->qi == $this->ql) {
      $this->look = FALSE;
      return;
    }
    $c = $this->qs[$this->qi];
    if (strchr("()/", $c)) {
      $this->look = $c;
      $this->qi++;
    }
    elseif (strchr("<>=", $c)) {
      $this->look = $c;
      $this->qi++;
      while ($this->qi < $this->ql && strchr("<>=", $this->qs[$this->qi])) {
        $this->look .= $this->qs[$this->qi];
        $this->qi++;
      }
    }
    elseif (strchr("\"'", $c)) {
      $this->look = 'q';
      $mark = $c;
      $this->qi++;
      $this->val = '';
      while ($this->qi < $this->ql && $this->qs[$this->qi] != $mark) {
        if ($this->qs[$this->qi] == '\\' && $this->qi < $this->ql - 1) 
          $this->qi++;
        $this->val .= $this->qs[$this->qi];
        $this->qi++;
      }
      $this->lval = strtolower($this->val);
      if ($this->qi < $this->ql) 
        $this->qi++;
    }
    else {
      $this->look = 's';
      $start_q = $this->qi;
      while ($this->qi < $this->ql && !strchr("()/<>= \t\r\n", $this->qs[$this->qi])) 
        $this->qi++;
      $this->val = substr($this->qs, $start_q, $this->qi - $start_q);
      $this->lval = strtolower($this->val);
    }
    self::dump_state('move');
  }
  
  /** \brief 
   * @param context (string)
   * @param target (string) the boolean or relation being modified
   **/
  private function modifiers($context, $target) {
    $ar = array();
    while ($this->look == '/') {
      self::move();
      if ($this->look != 's' && $this->look != 'q') {
        self::add_diagnostic(10, "$this->qi");
        return $ar;
      }
      $name = $this->lval;
      if (empty($this->supported_modifiers[$target][$name])) {
        self::add_diagnostic(46, "$this->qi", $name);
        return $ar;
      }
      $tgt_modifiers = &$this->supported_modifiers[$target][$name];
      self::move();
      if (strchr("<>=", $this->look[0])) {
        $rel = $this->look;
        if (!preg_match($tgt_modifiers['symbol'], $rel)) {
          self::add_diagnostic(40, "$this->qi", $rel);
        }
        self::move();
        if ($this->look != 's' && $this->look != 'q') {
          self::add_diagnostic(10, "$this->qi");
          return $ar;
        }
        if (!preg_match($tgt_modifiers['unit'], $this->lval)) {
          self::add_diagnostic($tgt_modifiers['error'], "$this->qi", $this->lval);
        }
        $ar[$name] = array('value' => $this->lval, 'relation' => $rel);
        self::move();
      }
      else 
        $ar[$name] = TRUE;
    }
    return $ar;
  }
  
  /** \brief 
   * @param field (string)
   * @param relation (string)
   * @param context (string)
   * @param modifiers (string)
   **/
  private function cqlQuery($field, $relation, $context, $modifiers) {
    $left = self::searchClause($field, $relation, $context, $modifiers);
    self::dump_state('cQ');
    while ($this->look == 's' && (in_array($this->lval, $this->booleans))) {
      if ($help = $this->boolean_translate[$this->lval]) {
        $this->lval = $help;
      }
      if (in_array($this->lval, $this->unsupported_booleans)) {
        self::add_diagnostic(37, "$this->qi", $this->lval);
      }
  // solr_4_4_0: for some unknown reason, the not operator has to be uppercase??
      $op = $this->lval;
      self::move();
      $mod = self::modifiers($context, $op);
      $right = self::searchClause($field, $relation, $context, $modifiers);
      $left = array('type' => 'boolean', 'op' => $op, 'modifiers' => $mod, 'left' => $left, 'right' => $right);
      self::dump_state('cQw');
    }
    return $left;
  }
  
  /** \brief 
   * @param field (string)
   * @param relation (string)
   * @param context (string)
   * @param modifiers (string)
   **/
  private function searchClause($field, $relation, $context, $modifiers) {
    if ($this->look == '(') {
      self::move();
      $b = self::cqlQuery($field, $relation, $context, $modifiers);
      if ($this->look == ')') {
        self::move();
      }
      else {
        self::add_diagnostic(13, "$this->qi");
      }
      return $b;
    }
    elseif ($this->look == 's' || $this->look == 'q') {
      $first = $this->val; // dont know if field or term yet
      self::move();
      
      if (($this->look == 'q' || ($this->look == 's') && in_array($this->lval, $this->defined_relations))) {
        if (in_array($this->lval, $this->unsupported_relations)) {
          self::add_diagnostic(19, "$this->qi", $this->lval);
        }
        $rel = $this->val; // string relation
        self::move();
        return self::searchClause($first, $rel, $context, self::modifiers($context, $rel));
      }
      elseif (in_array($this->look, $this->implicit_relations)) {
        if (in_array($this->look, $this->unsupported_relations)) {
          self::add_diagnostic(19, "$this->qi", $this->look);
        }
        $rel = $this->look; // other relation <, = ,etc
        self::move();
        return self::searchClause($first, $rel, $context, self::modifiers($context, $rel));
      }
      else {
        // it's a search term
        $pos = strpos($field, '.');
        if ($pos == FALSE) 
          $pre = '';
        else {
          $pre = substr($field, 0, $pos);
          $field = substr($field, $pos + 1, 100);
        }
        if ($alias = $this->indexes[$field][$pre]['alias']) {
          $slop = $alias['slop'];
          $pre = $alias['prefix'];
          $field = $alias['field'];
        }
        else {
          $slop = $this->indexes[$field][$pre]['slop'];
        }
        if (empty($this->indexes[$field][$pre])) {
          self::add_diagnostic(16, "$this->qi", $pre . '.' . $field);
        }
        $uri = $prefix = '';
        for ($i = 0; $i < sizeof($context); $i++) {
          if ($context[$i]['prefix'] == $pre)  {
            $uri = $context[$i]['uri'];
            $prefix = $context[$i]['prefix'];
          }
        }
        
        $pos = strpos($relation, '.');
        if ($pos == FALSE) 
          $pre = 'cql';
        else {
          $pre = substr($relation, 0, $pos);
          $relation = substr($relation, $pos + 1, 100);
        }
        $reluri = '';
        for ($i = 0; $i < sizeof($context); $i++) {
          if ($context[$i]['prefix'] == $pre) 
            $reluri = $context[$i]['uri'];
        }
        return array('type' => 'searchClause', 
                     'field' => $field, 
                     'prefix' => $prefix, 
                     'fielduri' => $uri, 
                     'relation' => $relation, 
                     'relationuri' => $reluri, 
                     'slop' => $slop,
                     'modifiers' => $modifiers, 
                     'term' => $first);
      }
    }
    elseif ($this->look == '>') {
      self::move();
      if ($this->look != 's' && $this->look != 'q') 
        return array();
      $first = $this->lval;
      self::move();
      if ($this->look == '=') {
        self::move();
        if ($this->look != 's' && $this->look != 'q') 
          return array();
        $context = self::add_prefix($context, $first, '', $this->lval);
        self::move();
        return self::cqlQuery($field, $relation, $context, $modifiers);
      }
      else {
        $context = self::add_prefix($context, '', '', $first);
        return self::cqlQuery($field, $relation, $context, $modifiers);
      }
    }
    else {
      self::add_diagnostic(10, "$this->qi");
    }
  }
  
  /** \brief 
   * @param ar (string)
   * @param prefix (string)
   * @param title (string)
   * @param uri (string)
   **/
  private function add_prefix($ar, $prefix, $title, $uri) {
    if (!is_array($ar)) 
      $ar = array();
    for ($i = 0; $i < sizeof($ar); $i++) 
      if ($ar[$i]['prefix'] == $prefix) 
        break;
    $ar[$i] = array('prefix' => $prefix, 'title' => $title, 'uri' => $uri);
    return $ar;
  }
  
  /** \brief 
   * @param ar (string)
   * @param level (string)
   **/
  private function tree2xml_modifiers($ar, $level) {
    if (sizeof($ar) == 0) {
      return "";
    }
    $s = str_repeat(' ', $level);
    $s .= "<modifiers>\n";
    
    $k = array_keys($ar);
    
    foreach ($k as $no => $key) {
      $s .= str_repeat(' ', $level + 1);
      $s .= "<modifier>\n";
      
      $s .= str_repeat(' ', $level + 2);
      $s .= '<name>' . htmlspecialchars($key) . "</name\n";
      
      if (isset($ar[$key]['relation'])) {
        $s .= str_repeat(' ', $level + 2);
        $s .= '<relation>' . htmlspecialchars($ar[$key]['relation']) . "</relation>\n";
      }
      if (isset($ar[$key]['value'])) {
        $s .= str_repeat(' ', $level + 2);
        $s .= '<value>' . htmlspecialchars($ar[$key]['value']) . "</value>\n";
      }
      $s .= str_repeat(' ', $level + 1);
      $s .= "</modifier>\n";
    }
    $s .= str_repeat(' ', $level);
    $s .= "</modifiers>\n";
    return $s;
  }
  
  /** \brief 
   * @param context (string)
   **/
  private function tree2xml_indent($level) {
    return str_repeat(' ', $level * 2);
  }
  
  /** \brief 
   * @param ar (string)
   * @param level (string)
   **/
  private function tree2xml_r($ar, $level) {
    $s = '';
    if (!isset($ar['type'])) {
      return $s;
    }
    if ($ar['type'] == 'searchClause') {
      $s .= self::tree2xml_indent($level);
      $s .= "<searchClause>\n";
      

      if (strlen($ar['fielduri'])) {
        $s .= self::tree2xml_indent($level + 1);
        $s .= "<prefixes>\n";
        $s .= self::tree2xml_indent($level + 2);
        $s .= "<prefix>\n";
        $s .= self::tree2xml_indent($level + 3);
        $s .= "<identifier>" . $ar['fielduri'] . "</identifier>\n";
        $s .= self::tree2xml_indent($level + 2);
        $s .= "</prefix>\n";
        $s .= self::tree2xml_indent($level + 1);
        $s .= "</prefixes>\n";
      }
      $s .= self::tree2xml_indent($level + 1);
      $s .= '<index>' . htmlspecialchars($ar['field']) . "</index>\n";
      
      $s .= self::tree2xml_indent($level + 1);
      $s .= "<relation>\n";
      if (strlen($ar['relationuri'])) {
        $s .= self::tree2xml_indent($level + 2);
        $s .= '<identifier>' . $ar['relationuri'] . "</identifier>\n";
      }
      $s .= self::tree2xml_indent($level + 2);
      $s .= "<value>" . htmlspecialchars($ar['relation']) . "</value>\n";
      $s .= self::tree2xml_modifiers($ar['modifiers'], $level + 3);
      
      $s .= self::tree2xml_indent($level + 1);
      $s .= "</relation>\n";
      
      $s .= self::tree2xml_indent($level + 1);
      $s .= '<term>' . htmlspecialchars($ar['term']) . "</term>\n";
      
      $s .= self::tree2xml_indent($level);
      $s .= "</searchClause>\n";
    }
    elseif ($ar['type'] == 'boolean') {
      $s .= self::tree2xml_indent($level);
      $s .= "<triple>\n";
      
      $s .= self::tree2xml_indent($level + 1);
      $s .= "<boolean>\n";
      
      $s .= self::tree2xml_indent($level + 2);
      $s .= "<value>" . htmlspecialchars($ar['op']) . "</value>\n";
      
      $s .= self::tree2xml_modifiers($ar['modifiers'], $level + 2);
      
      $s .= self::tree2xml_indent($level + 1);
      $s .= "</boolean>\n";
      
      $s .= self::tree2xml_indent($level + 1);
      $s .= "<leftOperand>\n";
      $s .= self::tree2xml_r($ar['left'], $level + 2);
      $s .= self::tree2xml_indent($level + 1);
      $s .= "</leftOperand>\n";
      
      $s .= self::tree2xml_indent($level + 1);
      $s .= "<rightOperand>\n";
      $s .= self::tree2xml_r($ar['right'], $level + 2);
      $s .= self::tree2xml_indent($level + 1);
      $s .= "</rightOperand>\n";
      
      $s .= self::tree2xml_indent($level);
      $s .= "</triple>\n";
    }
    return $s;
  }
  
  /** \brief 
   * @param id (integer)
   * @param details (string)
   **/
  private function add_diagnostic($id, $pos, $details = '') {
    $this->parse_ok = FALSE;
    if (is_int($id) && $id >= 0 && is_string($details)) {
      $this->diagnostics[] = array('no' => $id, 'description' => self::diag_message($id), 'details' => $details, 'pos' => $pos);
    }
  }

  /** \brief 
   * @param id (integer)
   **/
  private function diag_message($id) {
/* Total list at: http://www.loc.gov/standards/sru/diagnostics/diagnosticsList.html */
    static $message = 
      array(10 => 'Query syntax error',
            13 => 'Invalid or unsupported use of parentheses',
            16 => 'Unsupported index',
            19 => 'Unsupported relation',
            20 => 'Unsupported relation modifier',
            37 => 'Unsupported boolean operator',
            40 => 'Unsupported proximity relation',
            41 => 'Unsupported proximity distance',
            42 => 'Unsupported proximity unit',
            46 => 'Unsupported boolean modifier');

    return $message[$id];
  }

  private function dump_state($where) {
    return;
    $str = sprintf('%6s:: val: %-8s lval: %-8s look: %-2s qi: %-2s ql: %-2s', $where, $this->val, $this->lval, $this->look, $this->qi, $this->ql);
    echo $str . PHP_EOL;
  }
}

