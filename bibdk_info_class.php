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



require_once('verbose_class.php');
require_once('oci_class.php');
require_once('memcache_class.php');


define('ascii_US', "\037");   // unit seperator
define('SYSTEM', 'bibdk');
define('COOKIE_NAME', SYSTEM . '_opt');
define('COOKIE_FAVORIT', SYSTEM . '_fav');
define('COOKIE_VAR', 'start_screen step element bib_nr client_mailing kan_kobes download lingo skip_bestil_4 mail_bestil need_before target lang0 lang1 lang2 lang3 lang4 handicap lang5 lang6 lang7 udfoldet menu_simpel amenu kmenu nmenu mmenu rmenu artikel_alle artikel_avis artikel_tidsskrift bøger_alle bøger_punktskrift bøger_storskrift film_alle film_dvd film_video lydbog_alle lydbog_cd lydbog_baand musik_alle musik_lp musik_kassettebånd noder_alle openurl_prefs lydbog_nota');
define('COOKIE_BIB_VAR', 'client_cpr client_id client_barcode client_cardno client_pincode client_text client_db client_name client_address client_email client_phone bogbus');



/** \brief bibdk_info class
*
* Henter info om en bibdk bruger, inklusiv liste over favoritbiblioteker med bruger opsætninger
*   
*/

class bibdk_info {

  private $oci;
  private $error;
  private static $memcache;

/** \brief __construct
*
* Constructor - sætter oci credentials op
* 
*/
  public function __construct($oci_credentials,$cache_settings=null) {
    $this->oci = new Oci($oci_credentials);
    $this->oci->set_charset('UTF8');
    $this->oci->connect();
    $this->error = $this->oci->get_error_string();

    if (isset($cache_settings) && !is_object($this->memcache)) {
      $this->memcache = new cache($cache_settings['host'], $cache_settings['port'], $cache_settings['expire']);
      if (!$this->memcache->check()) {
        $this->memcache = null;    
      }
    }
  }


  /** \brief get_bib_info
   *
* Henter info om et givet bibliotek givet udfra data i Sessions Cookien
*
* @param integer $bib Bibliotekskode
* 
* @return array Bibdk data hentet fra VIP basen
* 
*/
  public function get_bib_info($bibno) {
    if ($this->error) return null;
    if (empty($bibno)) return null;

    if (isset($this->memcache)) {
      $cachekey = 'bibdk_info_' . $bibno;
      if ($ret = $this->memcache->get($cachekey))
        return $ret;
    }

    $this->oci->bind('bind_bibno', $bibno);
    $this->oci->set_query(
      'SELECT *
         FROM vip, vip_vsn, vip_danbib, vip_kat, vip_txt
         WHERE vip.bib_nr = :bind_bibno
          AND vip.bib_nr = vip_danbib.bib_nr(+)
          AND vip.bib_nr = vip_kat.bib_nr(+)
          AND vip.kmd_nr = vip_vsn.kmd_nr(+)
          AND vip.bib_nr = vip_txt.bib_nr(+)');
    $buf = $this->oci->fetch_into_assoc();
    if (empty($buf)) return null;
    $ret = array_change_key_case($buf, CASE_LOWER);
    
    if (isset($this->memcache)) 
      $this->memcache->set($cachekey, $ret);

    return $ret;
  }

 /** \brief  library info for libraries retrieved via openuserinfo webservice
   * and merge with info from vip.
   *
   * @params array favourites; favorite libraries [agencyId] => [data]
   * return array ret; [favourite][key]=> array[vip + openuserinfo userdata]
   **/
  public function get_oui_info($favourites){
    $ret = array();
    foreach ($favourites as $key => $info){
      $ret['favorit'][$key] = $this->_map_oui_user(unserialize($info['oui:userData']));
      $bib_info = self::get_bib_info($key);
      $ret['favorit'][$key] += $bib_info;
    }
    return $ret;
  }

  /** \brief map keys from openuserinfo webservice to keys used throughout old.bibliotek.dk
   *  eg. cpr => client_cpr etc.
   * @param array userData; userdata from openuserinfo webservice eg. [pincode=>1234, userName=testhest]
   * return array info; mapped data eg. [client_pincode=>1234, client_name=>testhest]
   *
   **/
  private function _map_oui_user($userData){
    $oui_map = array('cpr' => 'client_cpr',
             'userId' => 'client_id',
             'barcode' => 'client_barcode',
             'cardno' => 'client_cardno',
             'customId' => 'client_text',
             'pincode' => 'client_pincode',
             'userName' => 'client_name',
             'userMail' => 'client_email',
             'userAddress' => 'client_address',
             'userTelephone' => 'client_phone');

    $info = array();
    $id_value = NULL;
    foreach ($oui_map as $key => $val) {
      if (isset($userData[$key])) {
        $info[$val] = $userData[$key];
      }
    }
    return $info;
  }

 
 
  
/** \brief get_info
*
* Henter info om et givet bibliotek givet udfra data i Sessions Cookien
*
* @param array $session_data Sessions data fra Sessions Cookien
* 
* @return array Bibdk data hentet fra Sessions Cookien, suppleret med info fra VIP basen
* 
*/
  public function get_info($session_data) {
    $info = array();
    if ($this->error) return $info;
    $settings = unserialize($session_data);
    $info = $this->_map_info(explode(ascii_US, $settings[COOKIE_NAME]), explode(' ', COOKIE_VAR));
    $favs = explode(ascii_US, $settings[COOKIE_FAVORIT]);
    if (is_array($favs)) {
      foreach ($favs as $bibno) {
        if (empty($bibno)) continue;
        $info['favorit'][$bibno] = $this->_map_info(explode(ascii_US, $settings[COOKIE_NAME.'_'.$bibno]), explode(' ', COOKIE_BIB_VAR));
        $bib_info = self::get_bib_info($bibno);
        if (!empty($bib_info)) {
          $info['favorit'][$bibno] = array_merge($info['favorit'][$bibno], $bib_info);
        }
      }
    }
    return $info;
  }

  private function _map_info($from, $list) {
    $ret = array();
    if (is_array($list)) {
      foreach ($list as $key => $name) {
        if ($from[$key]) {
          $ret[$name] = $from[$key];
        }
      }
    }
    return $ret;
  }
}



?>
