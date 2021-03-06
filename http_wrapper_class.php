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

//==============================================================================


/**
 * http_wrapper - En abstrakt klasse til indkapsling af http service
 *
 * @author Steen L. Frederiksen
 * 
 */
 
require_once("curl_class.php");


/** \brief http_wrapper klasse
*
* http_wrapper klassen håndterer http request/responses
*   
*/

abstract class http_wrapper {
  

/** \brief Constructor
*
* Constructoren er tom
*
*/
  public function __construct() {}


/** \brief Destructor
*
* Destructoren er tom
*
*/
  public function __destruct() {}




/** \brief build
*
* Abstrakt metode til at opbygge en request meddelelse
*
* @param $parameters array  Associativt array indeholdene parametre for requesten
* 
* @retval string Den opbyggede ncip request som ren tekst
* 
*/
  abstract protected function build($parameters);


/** \brief parse
*
* Abstrakt metode til at parse en response
*
* @param $response string Responsen som ren tekst
* 
* @retval array Fortolkede værdier, udtrukket fra responsen
*
*/
  abstract protected function parse(&$response);




/** \brief request
*
* Sender requesten afsted, og afventer responsen.
* Requesten opbygges af den abstrakte metode $this->build, mens
* Responsen parses af den abstrakte metode $this->parse
*
* @param $url string Url adressen på lokalsystemet
* @param $data array Parametrene til den abstrakte $this->build metode
* @param $debug boolean
* 
* @retval array http responsen efter parsing af den abstrakte metode $this->parse
* 
*/
  public function request($url, $data, $debug = FALSE) {
    if (empty($url)) return array("Problem" => array("Type" => "No URL given in Request"));
    if (empty($data["Ncip"])) return array("Problem" => array("Type" => "No Ncip Type given in Request"));
    
    $curl = new curl();
    if (defined("HTTP_PROXY")) $curl->set_proxy(HTTP_PROXY);
    if (defined("SSL_VERSION")) $curl->set_option(CURLOPT_SSLVERSION, SSL_VERSION);
    if (defined('CONTENT_TYPE')) {
      $curl->set_post_with_header($this->build($data), 'Content-Type: ' . CONTENT_TYPE);
    } 
    else {
      $curl->set_post_xml($this->build($data));
    } 
    $res = $curl->get($url);
    $has_error = $curl->has_error();
    if ($debug && $has_error) {
      echo '<pre>' . print_r($curl->get_status(), TRUE) . '</pre>';
    }
    $curl->close();

    if (empty($res)) return array("Problem" => array("Type" => "Empty response"));
    if ($has_error) return array("Problem" => array("Type" => "Fejl"));
    return $this->parse($res);
  }

}
?>
