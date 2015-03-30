<?php

class weekcodeException extends Exception {
//  public function __toString() {
// return "marcException -->".$this-
}

class weekcode {

    private $db;
    private $tablename;
    private $parametertable;

    public function __construct($db) {
        $this->db = $db;
        $this->tablename = 'weekcodes';
        $this->parametertable = 'weekcodeparameters';

        $sql = "select tablename from pg_tables where tablename = $1";
        $arr = $db->fetch($sql, array($this->tablename));
        if (!$arr) {
            $sql = "create table " . $this->tablename . "( "
                    . "date timestamp with time zone, "
                    . "weekcode integer) ";
            $this->db->exe($sql);
        }
        $sql = "select tablename from pg_tables where tablename = $1";
        $arr = $db->fetch($sql, array($this->parametertable));
        if (!$arr) {
            $sql = "create table " . $this->parametertable . "( "
                    . "days int, "
                    . "numbers integer) ";
            $this->db->exe($sql);
        }
        $default = "insert into " . $this->parametertable . " "
                . "(days,numbers) values (4,1)";
        $this->db->exe($default);
    }

    function updateparameters($days, $numbers) {
        $update = "update " . $this->parametertable . " "
                . "set days = $days, numbers = $numbers ";
        $db->exe($update);
    }

    function getparameters() {
        $select = "select days, numbers "
                . "from " . $this->parametertable;
        $rows = $this->db->fetch($select);
        if ($rows) {
            return $rows[0];
        } else {
            return false;
        }
    }

    function insertweekcode($date, $weekcode) {
        $weekc = $this->getweekcode($date);
        if (!$weekc) {
            $insert = "insert into " . $this->tablename . " "
                    . "(date,weekcode) values "
                    . "( to_timestamp('$date','YYYYMMDD'), "
                    . "$weekcode )";
            $this->db->exe($insert);
        } else {
            $update = "update " . $this->tablename . " "
                    . "set weekcode =  '$weekcode' "
                    . "where to_char(date,'YYYYMMDD') = '$date'";
            $this->db->exe($update);
        }
    }

    /**
     *
     * @param type $weekcode
     *
     * returning an array with all the codes from the week "weekcode"
     */
    function getAllWeek($weekcode) {
        $year = substr($weekcode, 0, 4);
        $week_no = substr($weekcode, 4, 2);
        $weekcodes = array();
        $week_start = new DateTime();
        for ($day = 1; $day < 8; $day++) {
            $week_start->setISODate($year, $week_no, $day);
            $date = $week_start->format('Ymd');
            echo "date:$date\n";
            $weekcodes[$date] = $this->getweekcode($date);
        }
        return $weekcodes;
    }

    function getweekcode($date = '') {

        if ($date) {
            $today = $date;
        } else {
            $today = date('Ymd');
        }

        // is there an exception in the table?
        $sql = "select weekcode from " . $this->tablename . " "
                . "where to_char(date,'YYYYMMDD') = '$today' ";
        $rows = $this->db->fetch($sql);
        if ($rows) {
            return $rows[0]['weekcode'];
        }
        $year = substr($today, 0, 4);
        $month = substr($today, 4, 2);
        $day = substr($today, 6, 2);
        $ts = mktime(0, 0, 0, $month, $day, $year);
        $tsd = time();
        $par = $this->getparameters();

        $days = $par['days'] * 24 * 60 * 60;
        $numbers = $par['numbers'];

        $sec = (60 * 60 * 24 * 7) * $numbers;
        $weekcode = date('YW', $ts + $days + $sec);

        return $weekcode;
    }

}

?>