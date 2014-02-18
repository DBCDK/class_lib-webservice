<?php
set_include_path(get_include_path() . PATH_SEPARATOR .
                 __DIR__ . '/../simpletest' . PATH_SEPARATOR .
                 __DIR__ . '/..');
require_once('simpletest/autorun.php');
require_once('solr_query_class.php');

define('cql_file', '/tmp/simple_test_cql.xml');

class TestOfSolrQueryClass extends UnitTestCase {
  private $c2s;

  function __construct() {
    parent::__construct();
    if (@ $fp = fopen(cql_file, 'w')) {
      fwrite($fp, $this->cql_def());
      fclose($fp);
    }
    else {
      throw new Exception('Cannot write tmp-file: ' . cql_file);
    }
    $this->c2s = new SolrQuery(cql_file);
    $this->c2s->phrase_index = array('dkcclphrase', 'phrase', 'facet');
    //$this->c2s->best_match = TRUE;
  }

  function __destruct() { 
    unlink(cql_file);
  }

  function test_instantiation() {
    $this->assertTrue(is_object($this->c2s));
  }

  function test_basal() {
    $tests = array('et' => 'et');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_bool() {
    $tests = array('et AND to' => 'et and to',
                   'et AND to OR tre' => '((et and to) or tre)',
                   'et AND to OR tre AND fire' => '((et and to) or tre) and fire',
                   'et to OR tre fire' => '10',
                   '(et AND to) OR tre' => '((et and to) or tre)',
                   'et AND (to OR tre)' => 'et and (to or tre)',
                   '(et AND to' => '13',
                   'et AND to)' => '10)');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_simple_field() {
    $tests = array('dkcclphrase.cclphrase=en' => 'dkcclphrase.cclphrase:en',
                   'dkcclphrase.cclphrase="en to"' => 'dkcclphrase.cclphrase:"en to"',
                   'dkcclphrase.cclphrase=en AND to' => 'dkcclphrase.cclphrase:en and to',
                   'phrase.phrase=en' => 'phrase.phrase:en',
                   'phrase.phrase=en to' => '10',
                   'phrase.phrase=en AND to' => 'phrase.phrase:en and to',
                   'dkcclterm.cclterm=en' => 'dkcclterm.cclterm:en',
                   'dkcclterm.cclterm="en to"' => 'dkcclterm.cclterm:"en to"~999',
                   'dkcclterm.cclterm=en AND to' => 'dkcclterm.cclterm:en and to',
                   'dkcclterm.cclterm=en OR to' => '(dkcclterm.cclterm:en or to)',
                   'dkcclterm.cclterm=(en OR to)' => '(dkcclterm.cclterm:en or dkcclterm.cclterm:to)',
                   'facet.facet=en' => 'facet.facet:en',
                   'facet.facet=en to' => '10',
                   'term.term=en' => 'term.term:en',
                   'term.term=en to' => '10',
                   'term.term=en AND to' => 'term.term:en and to',
                   'term.term=en OR to' => '(term.term:en or to)',
                   'term.term=(en OR to)' => '(term.term:en or term.term:to)',
                   'phrase.xxx=to' => '16',
                   'xxx.term=to' => '16',
                   'facet.xxx=to' => '16',
                   'term.xxx=to' => '16');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_adjacency() {
    $tests = array('dkcclphrase.cclphrase ADJ "en to"' => 'dkcclphrase.cclphrase:"en to"',
                   'dkcclphrase.cclphrase ADJ "en to tre"' => 'dkcclphrase.cclphrase:"en to tre"',
                   'term.term ADJ "en to"' => 'term.term:"en to"~999',
                   'term.term ADJ "en to tre"' => 'term.term:"en to tre"~999');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_interval() {
    $tests = array('dkcclphrase.cclphrase < en' => 'dkcclphrase.cclphrase:[* TO em]',
                   'dkcclphrase.cclphrase > en' => 'dkcclphrase.cclphrase:[eo TO *]',
                   'dkcclphrase.cclphrase <= en' => 'dkcclphrase.cclphrase:[* TO en]',
                   'dkcclphrase.cclphrase >= en' => 'dkcclphrase.cclphrase:[en TO *]',
                   'dkcclterm.cclterm < en ' => 'dkcclterm.cclterm:[* TO em]',
                   'dkcclterm.cclterm > en' => 'dkcclterm.cclterm:[eo TO *]',
                   'dkcclterm.cclterm <= en' => 'dkcclterm.cclterm:[* TO en]',
                   'dkcclterm.cclterm >= en' => 'dkcclterm.cclterm:[en TO *]');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_complex() {
    $tests = array('facet.facet="karen blixen" AND term.term=bog' => 'facet.facet:"karen blixen" and term.term:bog');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_slop() {
    $tests = array('term.slop="karen"' => 'term.slop:karen',
                   'term.slop="karen blixen"' => 'term.slop:"karen blixen"~10');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_alias() {
    $tests = array('slop="karen"' => 'term.slop:karen',
                   'slop="karen blixen"' => 'term.slop:"karen blixen"~5');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function test_filter() {
    $tests = array('term.filter=filter' => '*',
                   'term.filter=filter and term.term="no filter"' => 'term.term:"no filter"~999');
    foreach ($tests as $send => $recieve) {
      $this->assertEqual($this->get_edismax($send), $recieve);
    }
  }

  function get_edismax($cql) {
    $help = $this->c2s->parse($cql);
//var_dump($help);
    if (isset($help['error'])) {
      return $help['error'][0]['no'];
    }
    if (isset($help['edismax']['q'])) {
      return implode(' and ', $help['edismax']['q']);
    }
    return 'no reply';
  }

  function cql_def() {
    return
'<explain>
  <indexInfo>
   <set identifier="info:srw/cql-context-set/1/cql-v1.1" name="cql"/>
   <set identifier="http://oss.dbc.dk/ns/dkcclphrase" name="dkcclphrase"/>
   <set identifier="http://oss.dbc.dk/ns/phrase" name="phrase"/>
   <set identifier="http://oss.dbc.dk/ns/dkcclterm" name="dkcclterm"/>
   <set identifier="http://oss.dbc.dk/ns/term" name="term"/>
   <set identifier="http://oss.dbc.dk/ns/facet" name="facet"/>
   <index><map><name set="cql">serverChoice</name></map></index>
   <index><map><name set="dkcclphrase">cclphrase</name></map></index>
   <index><map><name set="phrase">phrase</name></map></index>
   <index><map><name set="dkcclterm">cclterm</name></map></index>
   <index><map><name set="term" filter="1">filter</name></map></index>
   <index><map><name set="term">term</name></map></index>
   <index><map><name set="term" slop="10">slop</name>
               <alias set="" slop="5">slop</alias></map></index>
   <index><map><name set="facet">facet</name></map></index>
  </indexInfo>
  <configInfo>
   <supports type="danBooleanModifier">og</supports>
   <supports type="danBooleanModifier">eller</supports>
   <supports type="danBooleanModifier">ikke</supports>
   <supports type="engBooleanModifier">and</supports>
   <supports type="engBooleanModifier">or</supports>
   <supports type="engBooleanModifier">not</supports>
   <supports type="relation">&gt;=</supports>
   <supports type="relation">&gt;</supports>
   <supports type="relation">&lt;=</supports>
   <supports type="relation">&lt;</supports>
   <supports type="relation">=</supports>
   <supports type="relation">adj</supports>
   <supports type="maskingCharacter">?</supports>
   <supports type="maskingCharacter">*</supports>
   <supports type="booleanChar">(</supports>
   <supports type="booleanChar">)</supports>
  </configInfo>
</explain>';
  }
}
?>
