<?php

require_once("IDatabase_class.php");
/** \brief
  Class handles transactions for a postgres database;

  sample usage:

  $db=new pg_database('host=visoke port=5432 dbname=kvtestbase user=fvs password=fvs connect_timeout=1');

  SELECT
  $db->open();
  $db->bind('bind_timeid', 'xxxx');
  $db->bind('bind_seconds', 25);
  $db->set_query('SELECT time FROM stats_opensearch WHERE timeid = :bind_timeid AND seconds = :bind_seconds');
  $db->execute();
  $row = $pg->get_row();
  $db->close();

  SELECT without bind
  $db->open();
  $db->set_query('SELECT time FROM stats_opensearch WHERE timeid = \'xxxx\' AND seconds = 25');
  $db->execute();
  $row = $pg->get_row();
  $db->close();

  INSERT
  1. with sql
  $db->set_query("INSERT INTO stats_opensearch VALUES('2010-01-01', 'xxxx', '12.2')");
  $db->open();
  $db->execute();
  $db->close();
  2. with array
  $tablename='stats_opensearch';
  $record=array('time' => '2010-01-01 00:00:00', 'timeid' => 'xxxx', 'seconds' => '12.2');
  $db->open();
  $db->insert($tablename,$record);
  $db->close();

  UPDATE
  1. with sql
  $db->set_query("UPDATE stats_opensearch SET seconds=25, time=2009 WHERE timeid='xxxx'");
  $db->open();
  $db->execute();
  $db->close();
  2. with array(s)
  $tablename="stats_opensearch";
  $assign=array('time' => '2009', 'seconds' => '25');
  $clause=array('timeid' => 'xxxx');
  $db->open();
  $db->update($tablename, $assign, $clause);
  $db->close();

  DELETE
  1. with sql
  $db->set_query("DELETE FROM stats_opensearch WHERE timeid='xxxx' AND seconds='12.2'");
  $db->open();
  $db->execute();
  $db->close();
  2. with array
  $clause = array('timeid' => 'xxxx', 'seconds' => '12.2');
  $db->open();
  $db->delete('stats_opensearch', $clause);
  $db->close();

 */

/** DEVELOPER NOTES
  postgres-database class
  TO REMEMBER
  // to escape characters
  string pg_escape_string([resource $connection], string $data)

  // for blobs, clobs etc (large objects).
  pg_query($database, 'START TRANSACTION');
  $oid = pg_lo_create($database);
  $handle = pg_lo_open($database, $oid, 'w');
  pg_lo_write($handle, 'large object data');
  pg_lo_close($handle);
  pg_query($database, 'commit');

  // for error recovering
  bool pg_connection_reset(resource $connection)
 */
class Pg_database extends Fet_database {

  private $query_name;

  public function __construct($connectionstring) {
    $cred = array('user' => '', 'password' => '', 'dbname' => '', 'host' => '', 'port' => '', 'connect_timeout' => '5');
    $part = explode(" ", $connectionstring);
    foreach ($part as $key => $val) {
      if (!trim($val))
        continue;
      $pair = explode('=', $val);
      $cred[$pair[0]] = $pair[1];
    }
//         print_r($cred);
    parent::__construct($cred["user"], $cred["password"], $cred["dbname"], $cred["host"], $cred["port"], $cred["connect_timeout"]);
  }

  private function set_large_object() {
    // TODO implement
  }

  private function connectionstring() {
    $ret = "";

    if ($this->host)
      $ret.="host=" . $this->host;
    if ($this->port)
      $ret.=" port=" . $this->port;
    if ($this->database)
      $ret.=" dbname=" . $this->database;
    if ($this->username)
      $ret.=" user=" . $this->username;
    if ($this->password)
      $ret.=" password=" . $this->password;
    if ($this->connect_timeout)
      $ret.=" connect_timeout=" . $this->connect_timeout;

    // set connection timeout
    return $ret;
  }

  public function open() {
    /**
     * pg_pconnect has been altered to pg_connect.
     * We have had a lot of database connections before the altering.
     * Hopefully this will sold the problem.
     * From php manuaL
     * "You should not use pg_pconnect - it's broken. It will work but it doesn't really pool,
     * and it's behaviour is unpredictable. It will only make you rise the max_connections
     * parameter in postgresql.conf file until you run out of resources (which will slow
     * your database down)."
     *
     */
    if (($this->connection = pg_connect($this->connectionstring())) === FALSE)
      throw new fetException('no connection');
  }

  public function prepare($query_name, $query) {
    if (pg_prepare($this->connection, $query_name, $query) === FALSE) {
      $message = pg_last_error();
      throw new fetException("Prepare fejler : $message\n");
      // Følgende giver ikke rigtig nogen mening idet det også vil blive
      // udført hvis man kommer til at prepare samme statement to gange.
      // if ($this->transaction)
      // @pg_query($this->connection, "ROLLBACK");
      // @pg_query($this->connection, "DEALLOCATE ".$this->query_name);
    }
  }

  /**
    wrapper for private function _execute
   */
  public function execute($statement_key = NULL) {
    // set pagination
    if ($this->offset > -1 && $this->limit)
      $this->query.=' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset;

    try {
      $this->_execute($statement_key);
    } catch (Exception $e) {
      throw new fetException($e->__toString());
    }
  }

  /**
    return a proper key for the query
   */
  private function _queryname() {
    return str_replace(array(' ', ',', '(', ')'), '_', $this->query);
  }

  private function _execute($statement_key = NULL) {
    // use transaction if set
    if ($this->transaction)
      @pg_query($this->connection, 'START TRANSACTION');

    // check for bind-variables
    if (!empty($this->bind_list)) {
      $bind = array();
      foreach ($this->bind_list as $binds) {
        array_push($bind, $binds["value"]);
        $this->query = preg_replace('/(' . $binds['name'] . ')([^a-zA-Z0-9_]|$)/', '\$' . count($bind) . '\\2', $this->query);
      }
      unset($this->bind_list);
      if (isset($statement_key)) {
        $this->query_name = $statement_key;
      }
      else {
        $this->query_name = $this->_queryname();
        if (@pg_prepare($this->connection, $this->query_name, $this->query) === FALSE) {
          $message = pg_last_error();
          if ($this->transaction)
            @pg_query($this->connection, 'ROLLBACK');
          @pg_query($this->connection, 'DEALLOCATE ' . $this->query_name);
          throw new fetException($message);
        }
      }
      if (($this->result = @pg_execute($this->connection, $this->query_name, $bind)) === FALSE) {
        $message = pg_last_error();
        if ($this->transaction)
          @pg_query($this->connection, 'ROLLBACK');
        @pg_query($this->connection, 'DEALLOCATE ' . $this->query_name);
        throw new fetException($message);
      }
    }
    else
    // if no bind-variables - just query
    if (($this->result = @pg_query($this->connection, $this->query)) === FALSE) {
      $message = pg_last_error();
      if ($this->transaction)
        @pg_query($this->connection, 'ROLLBACK');
      throw new fetException($message);
    }
    if ($this->transaction)
      @pg_query($this->connection, 'COMMIT');
  }

  public function query_params($query = "", $params = array()) {
    if (($this->result = @pg_query_params($this->connection, $query, $params)) === FALSE) {
      $message = pg_last_error();
      if ($this->transaction)
        @pg_query($this->connection, 'ROLLBACK');
      throw new fetException($message);
    }
  }

  public function get_row() {
    return pg_fetch_assoc($this->result);
  }

  public function commit() {
    if ($this->transaction)
      pg_query($this->connection, 'COMMIT');
    // postgres has autocommit enabled by default
    // use only if TRANSACTIONS are used
  }

  public function rollback() {
    if ($this->transaction)
      pg_query($this->connection, 'ROLLBACK');
    // use only if TRANSACTIONS are used
  }

  public function close() {
    @pg_query($this->connection, 'DEALLOCATE ' . $this->query_name);
    if ($this->connection)
      pg_close($this->connection);
  }

  public function fetch($sql, $arr = "") {
    if ($arr)
      $this->query_params($sql, $arr);
    else
      $this->exe($sql);

    $data_array = pg_fetch_all($this->result);
    return $data_array;
  }

  public function exe($sql) {
    if (!$this->result = @pg_query($this->connection, $sql)) {
      $message = pg_last_error();
      throw new fetException("sql failed:$message \n $sql\n");
    }
  }

  public function __destruct() {

  }

}

?>
