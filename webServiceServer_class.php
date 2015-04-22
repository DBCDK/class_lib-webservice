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


/** \brief Webservice server
 *
 * @author Finn Stausgaard - DBC
 *
 */

require_once('OLS_class_lib/curl_class.php');
require_once('OLS_class_lib/verbose_class.php');
require_once('OLS_class_lib/inifile_class.php');
require_once('OLS_class_lib/timer_class.php');
require_once('OLS_class_lib/aaa_class.php');
require_once('OLS_class_lib/restconvert_class.php');
require_once('OLS_class_lib/jsonconvert_class.php');
require_once('OLS_class_lib/xmlconvert_class.php');
require_once('OLS_class_lib/objconvert_class.php');

abstract class webServiceServer {

  protected $config; ///< inifile object
  protected $watch; ///< timer object
  protected $aaa; ///< Authentication, Access control and Accounting object
  protected $xmldir = './'; ///< xml directory
  protected $validate = array(); ///< xml validate schemas
  protected $objconvert; ///< OLS object to xml convert
  protected $xmlconvert; ///< xml to OLS object convert
  protected $xmlns; ///< namespaces and prefixes
  protected $default_namespace; ///< -
  protected $tag_sequence; /**< tag sequence according to XSD or noame of XSD */
  protected $soap_action; ///< -
  protected $dump_timer; ///< -
  protected $dump_timer_ip; ///< -
  protected $output_type=''; ///< -
  protected $curl_recorder; ///< -
  protected $debug; ///< -
  protected $url_override; ///< array with special url-commands for the actual service


  /** \brief Webservice constructer
   *
  * @param $inifile string
   *
   */
  public function  __construct($inifile) {
    // initialize config and verbose objects
    $this->config = new inifile($inifile);

    if ($this->config->error) {
      die('Error: '.$this->config->error );
    }

    // service closed
    if ($http_error = $this->config->get_value('service_http_error', 'setup')) {
      header($http_error);
      die($http_error);
    }

    if ($this->config->get_value('only_https', 'setup') && empty($_SERVER['HTTPS'])) {
      header('HTTP/1.0 403.4 SSL Required');
      die('HTTP/1.0 403.4 SSL Required');
    }

    libxml_use_internal_errors(TRUE);

    if (self::in_house())
      $this->debug = $_REQUEST['debug'];
    verbose::open($this->config->get_value('logfile', 'setup'),
                  $this->config->get_value('verbose', 'setup'));
    $this->watch = new stopwatch('', ' ', '', '%s:%01.3f');

    if ($this->config->get_value('xmldir'))
      $this->xmldir=$this->config->get_value('xmldir');
    $this->xmlns = $this->config->get_value('xmlns', 'setup');
    $this->default_namespace = $this->xmlns[$this->config->get_value('default_namespace_prefix', 'setup')];
    $this->tag_sequence = $this->config->get_value('tag_sequence', 'setup');
    $this->version = $this->config->get_value('version', 'setup');
    $this->output_type = $this->config->get_value('default_output_type', 'setup');
    $this->dump_timer = str_replace('_VERSION_', $this->version, $this->config->get_value('dump_timer', 'setup'));
    if ($this->config->get_value('dump_timer_ip', 'setup'))
      $this->dump_timer_ip = 'ip:' . $_SERVER['REMOTE_ADDR'] . ' ';
    if (!$this->url_override = $this->config->get_value('url_override', 'setup'))
      $this->url_override = array('HowRU' => 'HowRU', 'ShowInfo' => 'ShowInfo', 'Version' => 'Version', 'wsdl' => 'Wsdl');

    $test_section = $this->config->get_section('test');
    if (is_array($test_section['curl_record_urls'])) {
      require_once('OLS_class_lib/curl_recorder.php');
      $this->curl_recorder = new CurlRecorder($test_section);
    }

    $this->aaa = new aaa($this->config->get_section('aaa'));
  }

  public function __destruct() { }

  /** \brief Handles request from webservice client
  *
  */
  public function handle_request() {
    foreach ($this->url_override as $query_par => $function_name) {
      if (strpos($_SERVER['QUERY_STRING'], $query_par) === 0 && method_exists($this, $function_name)) {
        return $this-> {$function_name}();
      }
    }
    if (isset($_POST['xml'])) {
      $xml=trim(stripslashes($_POST['xml']));
      self::soap_request($xml);
    }
    elseif (!empty($GLOBALS['HTTP_RAW_POST_DATA'])) {
      self::soap_request($GLOBALS['HTTP_RAW_POST_DATA']);
    }
    elseif (!empty($_SERVER['QUERY_STRING']) && ($_REQUEST['action'] || $_REQUEST['json'])) {
      self::rest_request();
    }
    elseif (!empty($_POST)) {
      foreach ($_POST as $k => $v) {
        $_SERVER['QUERY_STRING'] .= ($_SERVER['QUERY_STRING'] ? '&' : '') . $k . '=' . $v;
      }
      self::rest_request();
    }
    elseif (self::in_house()
            || $this->config->get_value('show_samples', 'setup')
            || ip_func::ip_in_interval($_SERVER['REMOTE_ADDR'], $this->config->get_value('show_samples_ip_list', 'setup'))) {
      self::create_sample_forms();
    }
    else {
      header('HTTP/1.0 404 Not Found');
    }
  }

  /** \brief Handles and validates soap request
    *
  * @param $xml string
  */
  private function soap_request($xml) {
    // Debug verbose::log(TRACE, 'Request ' . $xml);

    // validate request
    $this->validate = $this->config->get_value('validate');

    if ($this->validate['soap_request'] || $this->validate['request'])
      $error = ! self::validate_soap($xml, $this->validate, 'request');

    if (empty($error)) {
      // parse to object
      $this->xmlconvert=new xmlconvert();
      $xmlobj=$this->xmlconvert->soap2obj($xml);
      // soap envelope?
      if ($xmlobj->Envelope) {
        $request_xmlobj = &$xmlobj->Envelope->_value->Body->_value;
        $soap_namespace = $xmlobj->Envelope->_namespace;
      }
      else {
        $request_xmlobj = &$xmlobj;
        $soap_namespace = 'http://www.w3.org/2003/05/soap-envelope';
        $this->output_type = 'xml';
      }

      // initialize objconvert and load namespaces
      $this->objconvert = new objconvert($this->xmlns, $this->tag_sequence);
      $this->objconvert->set_default_namespace($this->default_namespace);

      // handle request
      if ($response_xmlobj = self::call_xmlobj_function($request_xmlobj)) {
        // validate response
        if ($this->validate['soap_response'] || $this->validate['response']) {
          $response_xml = $this->objconvert->obj2soap($response_xmlobj, $soap_namespace);
          $error = ! self::validate_soap($response_xml, $this->validate, 'response');
        }

        if (empty($error)) {
          // Branch to outputType
          list($service, $req) = each($request_xmlobj);
          if (empty($this->output_type) || $req->_value->outputType->_value)
            $this->output_type = $req->_value->outputType->_value;
          switch ($this->output_type) {
            case 'json':
              header('Content-Type: application/json');
              $callback = &$req->_value->callback->_value;
              if ($callback && preg_match("/^\w+$/", $callback))
                echo $callback . ' && ' . $callback . '(' . $this->objconvert->obj2json($response_xmlobj) . ')';
              else
                echo $this->objconvert->obj2json($response_xmlobj);
              break;
            case 'php':
              header('Content-Type: application/php');
              echo $this->objconvert->obj2phps($response_xmlobj);
              break;
            case 'xml':
              header('Content-Type: text/xml');
              echo $this->objconvert->obj2xmlNS($response_xmlobj);
              break;
            default:
              if (empty($response_xml))
                $response_xml =  $this->objconvert->obj2soap($response_xmlobj, $soap_namespace);
              if ($soap_namespace == 'http://www.w3.org/2003/05/soap-envelope' && empty($_POST['xml']))
                header('Content-Type: application/soap+xml');   // soap 1.2
              else
                header('Content-Type: text/xml; charset=utf-8');
              echo $response_xml;
          }
          // request done and response send, dump timer
          if ($this->dump_timer)
            verbose::log(TIMER, sprintf($this->dump_timer, $this->soap_action) .  ':: ' . $this->dump_timer_ip . $this->watch->dump());
        }
        else
          self::soap_error('Error in response validation.');
      }
      else
        self::soap_error('Incorrect SOAP envelope or wrong/unsupported request');
    }
    else
      self::soap_error('Error in request validation.');
  }

  /** \brief Handles rest request, converts it to xml and calls soap_request()
  *
  */
  private function rest_request() {
    // convert to soap
    if ($_REQUEST['json']) {
      $json = new jsonconvert($this->default_namespace);
      $xml = $json->json2soap($this->config);
    }
    else {
      $rest = new restconvert($this->default_namespace);
      $xml = $rest->rest2soap($this->config);
    }
    self::soap_request($xml);
  }

  /** \brief Show the service version
  *
  */
  private function version() {
    die($this->version);
  }

  /** \brief Show wsdl file for the service replacing __LOCATION__ with ini-file setting or current location
  *
  */
  private function Wsdl() {
    if ($wsdl = $this->config->get_value('wsdl', 'setup')) {
      if (!$location = $this->config->get_value('service_location', 'setup')) {
        $location = $_SERVER['SERVER_NAME'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
      }
      $protocol = 'http' . (empty($_SERVER['HTTPS'])? '' : 's') . '://';
      if (($text = file_get_contents($wsdl)) !== FALSE) {
        header('Content-Type: text/xml; charset="utf-8"');
        die(str_replace('__LOCATION__', $protocol . $location, $text));
      }
      else {
        die('ERROR: Cannot open the wsdl file - error in ini-file?');
      }
    }
    else {
      die('ERROR: wsdl not defined in the ini-file');
    }
  }

  /** \brief Show selected parts of the ini-file 
  *
  */
  private function ShowInfo() {
    if (($showinfo = $this->config->get_value('showinfo', 'showinfo')) && self::in_house()) {
      foreach ($showinfo as $line) {
        echo self::showinfo_line($line) . "\n";
      }
    die();
    }
  }

  /** \brief expands __var__ to the corresponding setting
  *
  * @param $line string
  */
  private function showinfo_line($line) {
    while (($s = strpos($line, '__')) !== FALSE) {
      $line = substr($line, 0, $s) . substr($line, $s+2);
      if (($e = strpos($line, '__')) !== FALSE) {
        $var = substr($line, $s, $e - $s);
        list($key, $section) = explode('.', $var, 2);
        $val = $this->config->get_value($key, $section);
        if (is_array($val)) {
          $val = self::implode_ini_array($val);
        }
        $line = str_replace($var . '__', $val, $line);
      }
    }
    return $line;
  }

  /** \brief Helper function to showinfo_line()
  *
  * @param $arr array
  * @param $prefix string
  */
  private function implode_ini_array($arr, $prefix = '') {
    $ret = "\n";
    foreach ($arr as $key => $val) {
      if (is_array($val)) {
        $val = self::implode_ini_array($val, ' - ' . $prefix);
      }
      $ret .= $prefix . ' - [' . $key . '] ' . $val . "\n";
    }
    return str_replace("\n\n", "\n", $ret);
  }

  /** \brief
  *  Return TRUE if the IP is in_house_domain
  */
  protected function in_house() {
    static $homie;
    if (!isset($homie)) {
      if (!$domain = $this->config->get_value('in_house_domain', 'setup'))
        $domain = '.dbc.dk';
      @ $remote = gethostbyaddr($_SERVER['REMOTE_ADDR']);
      $domains = explode(';', $domain);
      foreach ($domains as $dm) {
        $dm = trim($dm);
        if ($homie = (strpos($remote, $dm) + strlen($dm) == strlen($remote)))
          if ($homie = (gethostbyname($remote) == $_SERVER['REMOTE_ADDR'])) // paranoia check
            break;
        }
    }
    return $homie;
  }

  /** \brief RegressionTest tests the webservice
  *
  * @param $arg string
  */
  private function RegressionTest($arg='') {
    if (! is_dir($this->xmldir.'/regression'))
      die('No regression catalouge');

    if ($dh = opendir($this->xmldir.'/regression')) {
      chdir($this->xmldir.'/regression');
      $reqs = array();
      while (($file = readdir($dh)) !== false)
        if (!is_dir($file) && preg_match('/xml$/',$file,$matches))
          $fnames[] = $file;
      if (count($fnames)) {
        asort($fnames);
        $curl = new curl();
        $curl->set_option(CURLOPT_POST, 1);
        foreach ($fnames as $fname) {
          $contents = str_replace("\r\n", PHP_EOL, file_get_contents($fname));
          $curl->set_post_xml($contents);
          $reply = $curl->get($_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
          echo $reply;
        }
      }
      else
        die('No files found for regression test');
    }
    else
      die('Cannot open regression catalouge: ' . $this->xmldir.'/regression');
  }

  /** \brief HowRU tests the webservice and answers "Gr8" if none of the tests fail. The test cases resides in the inifile.
  *
  *  Handles zero or more set of tests.
  *  Each set, can contain one or more tests, where just one of them has to succeed
  *  If all tests in a given set fails, the corresponding error will be displayed
  */
  private function HowRU() {
    $tests = $this->config->get_value('test', 'howru');
    if ($tests) {
      $curl = new curl();
      $reg_matchs = $this->config->get_value('preg_match', 'howru');
      $reg_errors = $this->config->get_value('error', 'howru');
      if (!$server_name = $this->config->get_value('server_name', 'howru')) {
         if (!$server_name = $_SERVER['SERVER_NAME']) {
           $server_name = $_SERVER['HTTP_HOST'];
         }
      }
      $url = $server_name. $_SERVER['PHP_SELF'];
      if ($_SERVER['HTTPS'] == 'on') $url = 'https://' . $url;
      foreach ($tests as $i_test => $test) {
        if (is_array($test)) {
          $reg_match = $reg_matchs[$i_test];
        }
        else {
          $test = array($test);
          $reg_match = array($reg_matchs[$i_test]);
        }
        $error = $reg_errors[$i_test];
        foreach ($test as $i => $t) {
          $reply=$curl->get($url.'?action='.$t);
          $preg_match=$reg_match[$i];
          if (preg_match("/$preg_match/",$reply)) {
            unset($error);
            break;
          }
        }
        if ($error)
          die($error);
      }
      $curl->close();
    }
    die('Gr8');
  }

  /** \brief Validates soap and xml
  *
  * @param $soap string
  * @param $schemas array
  * @param $validate_schema string
    *
  */

  protected function validate_soap($soap, $schemas, $validate_schema) {
    $validate_soap = new DomDocument;
    $validate_soap->preserveWhiteSpace = FALSE;
    @ $validate_soap->loadXml($soap);
    if (($sc = $schemas['soap_'.$validate_schema]) && ! @ $validate_soap->schemaValidate($sc))
      return FALSE;

    if ($sc = $schemas[$validate_schema]) {
      if ($validate_soap->firstChild->localName == 'Envelope'
          && $validate_soap->firstChild->hasChildNodes()) {
        foreach ($validate_soap->firstChild->childNodes as $soap_node) {
          if ($soap_node->localName == 'Body') {
            $xml = &$soap_node->firstChild;
            $validate_xml = new DOMdocument;
            @ $validate_xml->appendChild($validate_xml->importNode($xml, TRUE));
            break;
          }
        }
      }
      if (empty($validate_xml))
        $validate_xml = &$validate_soap;

      if (! @ $validate_xml->schemaValidate($sc))
        return FALSE;
    }

    return TRUE;
  }

  /** \brief send an error header and soap fault
  *
  * @param $err string
  *
  */
  protected function soap_error($err) {
    $elevel = array(LIBXML_ERR_WARNING => "\n Warning",
                    LIBXML_ERR_ERROR => "\n Error",
                    LIBXML_ERR_FATAL => "\n Fatal");
    if ($errors = libxml_get_errors()) {
      foreach ($errors as $error) {
        $xml_err .= $elevel[$error->level] . ": " .  trim($error->message) .
                    ($error->file ? " in file " . $error->file : " on line " . $error->line);
      }
    }
    header('HTTP/1.0 400 Bad Request');
    header('Content-Type: text/xml; charset="utf-8"');
    echo '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Body>
    <SOAP-ENV:Fault>
    <faultcode>SOAP-ENV:Server</faultcode>
    <faultstring>' . htmlspecialchars($err . $xml_err) . '</faultstring>
    </SOAP-ENV:Fault>
    </SOAP-ENV:Body>
    </SOAP-ENV:Envelope>';
  }

  /** \brief Validates xml
  *
  * @param $xml string
  * @param $schema_filename string
  * @param $resolve_externals boolean
    *
  */

  protected function validate_xml($xml, $schema_filename, $resolve_externals=FALSE) {
    $validateXml = new DomDocument;
    $validateXml->resolveExternals = $resolve_externals;
    $validateXml->loadXml($xml);
    if (@ $validateXml->schemaValidate($schema_filename)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /** \brief Find operation in object created from xml and and calls this function defined by developer in extended class.
  * Authentication is by default found in the authentication node, in userIdAut, groupIdAut and passwordAut
  * These names can be changed by doing so in the aaa-section, like: 
  * userIdAut = theNameOfUserIdInThisService
  *
  * @param $xmlobj object
  * @retval object - from the service entry point called
  *
  */
  private function call_xmlobj_function($xmlobj) {
    if ($xmlobj) {
      $soapActions = $this->config->get_value('soapAction', 'setup');
      $request=key($xmlobj);
      if ($this->soap_action = array_search($request, $soapActions)) {
        $params=$xmlobj->$request->_value;
        if (method_exists($this, $this->soap_action)) {
          if (is_object($this->aaa)) {
            foreach (array('authentication', 'userIdAut', 'groupIdAut', 'passwordAut') as $par) {
              if (!$$par = $this->config->get_value($par, 'aaa')) {
                $$par = $par;
              }
            }
            $auth = &$params->$authentication->_value;
            $this->aaa->init_rights($auth->$userIdAut->_value,
                                    $auth->$groupIdAut->_value,
                                    $auth->$passwordAut->_value,
                                    $_SERVER['REMOTE_ADDR']);
          }
          return $this-> {$this->soap_action}($params);
        }
      }
    }

    return FALSE;
  }

  /** \brief Create sample form for testing webservice. This is called of no request is send via browser.
  *
  *
  */

  private function create_sample_forms() {
    if ($sample_header = $this->config->get_value('sample_header', 'setup')) {
      $header_warning = '<p>Ensure that the character set of the request match your browser settings</p>';
    }
    else {
      $sample_header = 'Content-type: text/html; charset=utf-8';
    }
    header ($sample_header);

    // Open a known directory, and proceed to read its contents
    if (is_dir($this->xmldir.'/request')) {
      if ($dh = opendir($this->xmldir.'/request')) {
        chdir($this->xmldir.'/request');
        $fnames = $reqs = array();
        while (($file = readdir($dh)) !== false) {
          if (!is_dir($file)) {
            if (preg_match('/html$/',$file,$matches)) $info = file_get_contents($file);
            if (preg_match('/xml$/',$file,$matches)) $fnames[] = $file;
          }
        }
        closedir($dh);

        $html = strpos($info, '__REQS__') ? $info : str_replace('__INFO__', $info, self::sample_form());

        if ($info || count($fnames)) {
          asort($fnames);
          foreach ($fnames as $fname) {
            $contents = str_replace("\r\n", PHP_EOL, file_get_contents($fname));
            $contents=addcslashes(str_replace("\n",'\n',$contents), '"');
            $reqs[]=$contents;
            $names[]=$fname;
          }

          foreach ($reqs as $key => $req)
            $options .= '<option value="' . $key . '">'.$names[$key].'</option>' . "\n";
          if ($_GET['debug'] && self::in_house())
            $debug = '<input type="hidden" name="debug" value="' . $_GET['debug'] . '">';

          $html = str_replace('__REQS__', implode("\",\n\"", $reqs), $html); 
          $html = str_replace('__XML__', htmlspecialchars($_REQUEST['xml']), $html); 
          $html = str_replace('__OPTIONS__', $options, $html); 
        }
        else {
          $error = 'No example xml files found...';
        }
        $html = str_replace('__ERROR__', $error, $html); 
        $html = str_replace('__DEBUG__', $debug, $html); 
        $html = str_replace('__HEADER_WARNING__', $header_warning, $html); 
        $html = str_replace('__VERSION__', $this->version, $html); 
      }
    }
    echo $html;
  }

  private function sample_form() {
    return 
'<html><head>
__HEADER_WARNING__
<script language="javascript">
  var reqs = Array("__REQS__");
</script>
</head><body>
  <form target="_blank" name="f" method="POST" accept-charset="utf-8">
    <textarea name="xml" rows=20 cols=90>__XML__</textarea>
    <br /> <br />
    <select name="no" onChange="if (this.selectedIndex) document.f.xml.value = reqs[this.options[this.selectedIndex].value];">
      <option>Pick a test-request</option>
      __OPTIONS__
    </select>
    <input type="submit" name="subm" value="Try me">
    __DEBUG__
  </form>
  __INFO__
  __ERROR__
  <p style="font-size:0.6em">Version: __VERSION__</p>
</body></html>';
  }

}

?>
