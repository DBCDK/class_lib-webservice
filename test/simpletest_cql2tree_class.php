<?php
set_include_path(get_include_path() . PATH_SEPARATOR .
                 __DIR__ . '/../simpletest' . PATH_SEPARATOR .
                 __DIR__ . '/..');
require_once('simpletest/autorun.php');
require_once('cql2tree_class.php');


class TestOfCql2TreeClass extends UnitTestCase {
  private $c2t;

  function __construct() {
    parent::__construct();
    $this->c2t = new CQL_parser();
    $this->c2t->set_prefix_namespaces(self::cqlns());
    $this->c2t->set_indexes(self::indexes());
  }

  function __destruct() { 
  }

  function test_instantiation() {
    $this->assertTrue(is_object($this->c2t));
  }

  function test_basal() {
    $query = 'test';
    $this->c2t->parse($query);
    $tree = $this->c2t->result();
    $this->assertEqual($tree['type'], 'searchClause');
    $this->assertEqual($tree['term'], 'test');
  }

  function test_bool() {
    $query = 'test and some';
    $this->c2t->parse($query);
    $tree = $this->c2t->result();
    $this->assertEqual($tree['type'], 'boolean');
    $this->assertEqual($tree['op'], 'and');
    $this->assertEqual($tree['left']['term'], 'test');
    $this->assertEqual($tree['right']['term'], 'some');
  }

  function test_simple_field() {
  }

  function test_adjacency() {
  }

  function test_interval() {
  }

  function test_complex() {
  }

  function test_slop() {
  }

  function test_alias() {
  }

  function test_filter() {
  }

  function test_trunkation() {
  }

  function test_masking() {
  }

  function test_errors() {
  }

  function cqlns() {
    return array('cql' => 'info:srw/cql-context-set/1/cql-v1.1',
                 'dkcclphrase' => 'http://oss.dbc.dk/ns/dkcclphrase',
                 'phrase' => 'http://oss.dbc.dk/ns/phrase',
                 'dkcclterm' => 'http://oss.dbc.dk/ns/dkcclterm',
                 'term' => 'http://oss.dbc.dk/ns/term',
                 'facet' => 'http://oss.dbc.dk/ns/facet');
  }
  
  function indexes() {
    return array('serverChoice' => array('cql' => array('filter' => false, 'slop' => 9999, 'handler' => '')),
                 'cclphrase' => array('dkcclphrase' => array('filter' => false, 'slop' => 9999, 'handler' => '')),
                 'phrase' => array('phrase' => array('filter' => false, 'slop' => 9999, 'handler' => '')),
                 'cclterm' => array('dkcclterm' => array('filter' => false, 'slop' => 9999, 'handler' => '')),
                 'filter' => array('term' => array('filter' => true, 'slop' => 9999, 'handler' => '')),
                 'term' => array('term' => array('filter' => false, 'slop' => 9999, 'handler' => '')),
                 'slop' => array('term' => array('filter' => false, 'slop' => 10, 'handler' => ''),
                                 '' => array('alias' => array('slop' => 5, 'handler' => '', 'prefix' => 'term', 'field' => 'slop'))),
                 'facet' => array('facet' => array('filter' => false, 'slop' => 9999, 'handler' => '')));
  }

}
?>
