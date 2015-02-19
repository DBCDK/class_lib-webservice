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
//$startdir = dirname(__FILE__);
//$inclnk = $startdir . "/../inc";

require_once 'marc_class.php';
require_once "verbose_class.php";
require_once "oci_class.php";

class LibV3API {

//  private $oci;
    private $withAuthor;
    private $hsb;
    private $return;
    private $oci;
    private $PhusOci;
    private $BasisOci;

    function __construct($ociuser, $ocipasswd, $ocidatabase) {
        $this->oci = new oci($ociuser, $ocipasswd, $ocidatabase);
        $this->oci->set_charset('WE8ISO8859P1');
        $this->oci->connect();
        $this->BasisOci = $this->oci;
    }

    function setPhusLogin($ociuser, $ocipasswd, $ocidatabase) {
        $this->PhusOci = new oci($ociuser, $ocipasswd, $ocidatabase);
        $this->PhusOci->set_charset('WE8ISO8859P1');
        $this->PhusOci->connect();
    }

    function close() {
        $this->BasisOci->disconnect();
        $this->PhusOci->disconnect();
    }

    /**
     *
     * @param type $name
     * @param type $value
     *
     * set various parameters
     * withAuthor: the 100/770 field in the record will be data from the authority database.
     * hsb:  head bind section. all records (from this and up) will be fetched
     *
     */
    function set($name, $value = true) {
        switch ($name) {
            case 'withAuthor' :
                $this->withAuthor = $value;
                break;
            case 'hsb':
                $this->hsb = $value;
                break;
            case 'Basis' :
                $this->oci = $this->BasisOci;
                break;
            case 'Phus' :
                $this->oci = $this->PhusOci;
        }
    }

    function getDBtime() {
        $sql = "select to_char(current_timestamp,'DDMMYYYY HH24MISS') tid "
                . "from dual";
        $rows = $this->oci->fetch_all_into_assoc($sql);
        $tid = $rows[0]['TID'];
        return $tid;
    }

    /**
     *
     * @param type $lastupdate
     *
     * en forbedret udgave:
     */
    function getUpdatedRecs($lastupdated) {
        $sql = "select lokalid, to_char(ajourdato,'DDMMYYYY HH24MISS') as dato from poster "
                . "where ajourdato > to_date('$lastupdated','DDMMYYYY HH24MISS') "
                . "and bibliotek = '870970'  "
                . "and (lokalid like '2%' or lokalid like '5%')"
                . "order by ajourdato ";
        $sql = " select lokalid, to_char(ajourdato,'DDMMYYYY HH24MISS') as dato from poster
      where ajourdato > to_timestamp('$lastupdated','DDMMYYYY HH24MISS')
      and bibliotek = '870970'
      and ( lokalid like '2 %' or lokalid like '5 %')
      and (data like '%xNLY%' or data like '%xERE%' or data like '%xERL%')";

//        echo "sql:$sql\n";
        $updates = $this->oci->fetch_all_into_assoc($sql);
        return $updates;
    }

    /**
     *
     * @param string with iso2709 record $data
     * @return iso2709 type
     *
     * test wether there is a subfield '5' and/or '6' in field
     * 100/700.  If so replace data with the data fra the
     * authority record.
     */
    function insertAuthors($data) {
        $marc = new marc();
        $marc->fromIso($data);
        $ln = $marc->toLineFormat();
        $fields = array('100', '700');
        foreach ($fields as $field) {
            $bib = $lokalid = "";
            while ($marc->thisField($field)) {
                while ($marc->thisSubfield('5')) {
                    $bib = $marc->subfield();
                }
                while ($marc->thisSubfield('6')) {
                    $lokalid = $marc->subfield();
                }
                if ($bib && $lokalid) {
                    $autMarcs = $this->getMarcByLB($lokalid, $bib);
                    $autmarc = new marc();
                    $autmarc->fromIso($autMarcs[0]['DATA']);
                    $lns = $autmarc->toLineFormat();
                    $afields = array('100', '400');
                    $res = null;

                    foreach ($afields as $afield) {
                        if ($res != null) {
                            break;
                        }
                        $res = $autmarc->findFields($afield);
                        if ($res) {
                            $ln = $marc->toLineFormat();
                            foreach ($res as $rec) {
                                $rec['field'] = $field;
                                $marc->updateField($rec);
                            }
                            $ln = $marc->toLineFormat();
                        }
                    }
                }
            }
        }
        $data = $marc->toIso();
        return $data;
    }

    function fetchHSB($data) {
        $marc = new marc();
        $marc->fromIso($data);
        $lokalid = false;
        while ($marc->thisField('014')) {
            while ($marc->thisSubfield('a')) {
                $lokalid = $marc->subfield();
            }
        }
        if ($lokalid) {
            $bib = $marc->findSubFields('001', 'b');
            $result['bibliotek'] = $bib[0];
            $result['lokalid'] = $lokalid;
        }
        return $result;
    }

    function getMarcByDanbibid($danbibid, $bibliotek) {
        $where = "where danbibid = $danbibid and bibliotek = $bibliotek ";
        return $this->getMarcByLokalidBibliotek($lokalid, $bibliotek, $where);
    }

    function getMarcById($id) {
        $where = "where id = $id";
        return $this->getMarcByLokalidBibliotek($lokalid, $bibliotek, $where);
    }

    function getIdsByLokalidBibliotek($lokalid, $bibliotek) {
        $ids = array();
        $select = "select id from poster where lokalid = '$lokalid' and "
                . "bibliotek = '$bibliotek' ";
        $res = $this->oci->fetch_all_into_assoc($select);
        foreach ($res as $id) {
            $ids[] = $id['ID'];
        }
        return $ids;
    }

    function getMarcByLokalidBibliotek($lokalid, $bibliotek, $wh = "") {
        $this->return = array();
        $result = $this->getMarcByLB($lokalid, $bibliotek, $wh);
        if (!$result) {
            return $result;
        }
        $this->return[] = $result[0];
        if ($this->hsb) {
            $res = $this->fetchHSB($result[0]['DATA']);
            while ($res) {
                $result = $this->getMarcByLB($res['lokalid'], $res['bibliotek']);
                $this->return[] = $result[0];
                $res = $this->fetchHSB($result[0]['DATA']);
            }
        }

        if ($this->withAuthor) {
            for ($i = 0; $i < count($this->return); $i++) {
                $this->return[$i]['DATA'] = $this->insertAuthors($this->return[$i]['DATA']);
            }
        }

        return $this->return;
    }

    function getMarcByLB($lokalid, $bibliotek, $wh = "", $base = 'basis') {
        if ($wh) {
            $where = $wh;
        } else {
            $where = "where lokalid   = '$lokalid' and bibliotek = '$bibliotek'";
        }

        $sql = "select to_char(ajourdato, 'YYYYMMDD HH24MISS')ajour, to_char(opretdato, 'YYYYMMDD HH24MISS')opret,
                id,
                danbibid,
                lokalid,
                bibliotek,
                data
                from poster
                $where ";

        $result = $this->oci->fetch_all_into_assoc($sql);
        if (count($result) == 0)
            return $result;
        $data = $result[0]['DATA'];
        $id = $result[0]['ID'];
        $marclngth = substr($data, 0, 5);
        if ($marclngth > 4000) {
            $sql = "select data from poster_overflow "
                    . "where id = $id order by lbnr";
            $overflow = $this->oci->fetch_all_into_assoc($sql);
            foreach ($overflow as $record) {
                $data .= $record['DATA'];
            }
            $result[0]['DATA'] = $data;
        }

        return $result;
    }

}
