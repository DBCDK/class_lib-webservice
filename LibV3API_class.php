<?php

/**
 * @file LibV3API_class.php
 * @brief 1. version can only fetch a record from a libV3 database
 * by "lokalid" and "bibliotek"
 *
 * @author Hans-Henrik Lund
 *
 * @date 11-07-2012
 */
$startdir = dirname(__FILE__);
$inclnk = $startdir . "/../inc";

require_once "$inclnk/OLS_class_lib/verbose_class.php";
require_once "$inclnk/OLS_class_lib/oci_class.php";

class LibV3API {

//  private $oci;

  function __construct($ociuser, $ocipasswd, $ocidatabase) {
    $this->oci = new oci($ociuser, $ocipasswd, $ocidatabase);
    $this->oci->set_charset('WE8ISO8859P1');
    $this->oci->connect();

//    echo "ociuser:$ociuser $ocidatabase $ocipasswd\n";
//    $sql = "alter session set NLS_LANG='AMERICAN_DENMARK.WE8ISO8859P1'";
//    $sql = "alter session set NLS_LANGUAGE = AMERICAN";
//    $this->oci->set_query($sql);
//    $sql = "alter session set NLS_TERRITORY = DENMARK";
//    $this->oci->set_query($sql);
  }

  function getMarcByLokalidBibliotek($lokalid, $bibliotek) {


    $sql = "
      select to_char(ajourdato,'YYYYMMDD HH24MISS')ajour,
        to_char(opretdato,'YYYYMMDD HH24MISS')opret,
        id,
        danbibid,
        lokalid,
        bibliotek,
        data
      from poster where lokalid   = '$lokalid'
                    and bibliotek = '$bibliotek'
      ";
//    echo $sql;
    $result = $this->oci->fetch_all_into_assoc($sql);
    if (count($result) == 0)
      return $result;
//    print_r($result);
    $data = $result[0]['DATA'];
    $id = $result[0]['ID'];
    $marclngth = substr($data, 0, 5);
    if ($marclngth > 4000) {
      $sql = "
    select data from poster_overflow where id = $id order by lbnr
  ";
      $overflow = $this->oci->fetch_all_into_assoc($sql);
      foreach ($overflow as $record) {
        $data .= $record['DATA'];
      }
      $result[0]['DATA'] = $data;
//      print_r($result);
    }
    return $result;
  }

}
