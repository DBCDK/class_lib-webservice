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



class jsonconvert {

  //private $charset = 'ISO-8859-1';
  private $charset = "utf-8";
  private $soap_header;
  private $soap_footer;
  private $default_namespace = '';

  public function __construct($namespace='') {
    $this->soap_header='<?xml version="1.0" encoding="%s"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"%s><SOAP-ENV:Body>';
    $this->soap_footer='</SOAP-ENV:Body></SOAP-ENV:Envelope>';
    if ($namespace)
      $this->default_namespace = ' xmlns="' . $namespace . '"';
  }

  /** \brief Transform JSON parameters to SOAP-request
   *
   */
  public function json2soap($config) {
    $soap_actions = $config->get_value('soapAction', 'setup');
    $json = json_decode(self::get_post('json'));
    $action = $soap_actions[$json->action];
    if ($action && $json) {
      if ($json->charset) {
        $this->charset = $json->charset;
        unset($json->charset);
      }
      unset($json->action);
      $xml = self::tag_me($action, self::to_xml($json));
      return sprintf($this->soap_header, $this->charset, $this->default_namespace) .
                   $xml . 
                   $this->soap_footer;
    }
  }

  private function to_xml($obj) {
    foreach ($obj as $tag => $val) {
      if (is_scalar($val)) {
        $ret .= self::tag_me($tag, $val);
      }
      elseif (is_array($val)) {
        foreach ($val as $t => $v) {
          $ret .= self::tag_me($tag, self::to_xml($v));
        }
      }
      else {
        $ret .= self::tag_me($tag, self::to_xml($val));
      }
    }
    return $ret;
  }

  private function get_post($par) {
    return ($_GET[$par] ? $_GET[$par] : $_POST[$par]);
  }

  private function tag_me($tag, $val) {
    return '<' . $tag . ">" . $val . '</' . $tag. ">";
  }
}

?>
