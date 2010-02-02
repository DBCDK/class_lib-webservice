<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once("tokenizer_class.php");

class cql2solr extends tokenizer {

	var $tokenlist;
	var $dom;
	var $map;

  function cql2solr($xml, $config="") {
		$this->dom=new DomDocument();
		$this->dom->Load($xml);

    $this->case_insensitive=TRUE;
    $this->split_expression="/([ ()=])/";
    $this->operators=$this->get_operators();
    $this->indexes=$this->get_indexes();
    $this->ignore=array("/^prox\//");

		$this->map=array(
		  "and"=>"AND",
		  "not"=>"NOT",
		  "or"=>"OR",
		  "="=>":"
		);
    if ($config)
      $this->raw_index = $config->get_value("raw_index", "setup");
	}


	function get_indexes() {
		$indexInfo = $this->dom->getElementsByTagName('indexInfo');

		$i=0;
		foreach ($indexInfo as $indexinfo_key) {
  		$index = $indexInfo->item($i)->getElementsByTagName('name');

  		// get set attribs
  		$j=0;
  		foreach ($index as $index_key) {
        $indexes[]=$index->item($j)->getAttribute('set').".".$index->item($j)->nodeValue;
    		$j++;
  		}

  		$i++;
		}
		return $indexes;
	}

	function get_operators() {
		$supports = $this->dom->getElementsByTagName('supports');

    $i=0;
    foreach ($supports as $support_key) {
			$type=$supports->item($i)->getAttribute('type');
			if($type=="booleanModifier" || $type=="relation")
      	$operators[] = $supports->item($i)->nodeValue;
    
			$i++;
		}
		return $operators;
	}

	function dump() {
		echo "<PRE>";
		print_r($this->tokenlist);
	}


 /** \brief Parse a cql-query and build the solr search string
  * @param query the cql-query
  */
	function convert($query) {

		$return="";
    $this->tokenlist=$this->tokenize(str_replace('\"','"',$query));

    foreach($this->tokenlist as $k=>$v) {

      if($v["type"]=="OPERATOR") {
      	$string.= $this->map[strtolower($v["value"])];
      } else {
				$string.=$v["value"];
			}
    }
		return $string;
  }

 /** \brief Parse a cql-query and extract the operands. 
  * Build a dismax-boost string setting the dismax parameters:
  * - qf: QueryField - boost on words
  * - pf: PhraseField - boost on phrases
  * - tie: tiebreaker, less than 1
  * @param query the cql-query
  * @param rank the rank-settings
  */
  function dismax($query, $rank) {
    $qf = urlencode($this->make_boost($rank["word_boost"]));
    $pf = urlencode($this->make_boost($rank["phrase_boost"]));
    $this->tokenlist=$this->tokenize(str_replace('\"','"',$query));
    foreach($this->tokenlist as $k=>$v)
      if ($v["type"] == "OPERAND" && $v["value"] <> " ") 
        $elem .= ($elem ? " " : "") . $v["value"];

    if (empty($qf) && empty($pf)) return "";

    return '+AND+_query_:%22{!dismax' .
           ($qf ? "+qf='" . $qf . "'" : '') .
           ($pf ? "+pf='" . $pf . "'" : '') .
           ($rank["tie"] ? "+tie=" . $rank["tie"] : "") .
           '}' . urlencode($elem) . '%22';
  }

 /** \brief build a boost string
  * @param boosts boost registers and values
  */
  private function make_boost($boosts) {
    foreach ($boosts as $idx => $val)
      if ($idx && $val)
        $ret .= ($ret ? " " : "") . $idx . "^" . $val;
    return $ret;
  }
}

?>
