<?php

/**
 *
 * This file is part of Open Library System.
 * Copyright © 2018, Dansk Bibliotekscenter a/s,
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
/**
 * \brief Verbose singleton class for loggin to a file or syslog
 *        Use syslog://[facility] as filename for writing to syslog. facility defaults to LOG_LOCAL0
 *
 * Usage: \n
 * verbose::open(logfile_name, log_mask); \n
 * verbose::log(FATAL,'could not find value x')\n
 *
 * Example:
 * verbose::open('syslog://LOG_LOCAL0', log_mask); \n
 * verbose::log(FATAL,'could not find value x')\n
 *
 * Example:
 * verbose::open('my_trace_file.log', 'WARNING+FATAL+TIMER'); \n
 * verbose::log(FATAL, 'Cannot find database');\n
 *
 * Example:
 * verbose::open('my_trace_file.log', WARNING+FATAL+TIMER); \n
 * verbose::log(FATAL, 'Cannot find database');\n
 *
 * Example (will add process-id to all verbose lines):
 * verbose::open('my_trace_file.log', PID+WARNING+FATAL+TIMER); \n
 * verbose::log(FATAL, 'Cannot find database');\n
 *
 * Example:
 * verbose::open('my_trace_file.log', 77, 'H:i:s d:m:y'); \n
 * verbose::log(TRACE, 'db::look_up_user()');\n
 *
 * @author Finn Stausgaard - DBC
 * */
@ define('WARNING', 0x01);
@ define('ERROR', 0x02);
@ define('FATAL', 0x04);
@ define('STAT', 0x08);
@ define('TIMER', 0x10);
@ define('DEBUG', 0x20);
@ define('TRACE', 0x40);
@ define('Z3950', 0x80);
@ define('OCI', 0x100);

@ define('SYSLOG_PREFIX', 'syslog://');

/**
 * Class verbose
 */
class VerboseJson {

  static $verbose_file_name;      ///< -
  static $syslog_facility = NULL; ///< -
  static $syslog_id = '';         ///< -
  static $style = 'json';         ///< -
  static $verbose_mask;           ///< -
  static $date_format;            ///< -
  static $ip = '';                ///< -
  static $action = '';            ///< -
  static $tracking_id = '';       ///< -

  /**
   * verbose constructor.
   */
  private function __construct() {
  }

  private function __clone() {
  }

  /**
   * \brief Sets loglevel and logfile
   * @param array $settings
   * */
  static public function open($settings) {
    self::$tracking_id = date('Y-m-d\TH:i:s:') . substr((string)microtime(), 2, 6) . ':' . getmypid();
    if (!self::$date_format = $settings['date_format'])
      // ISO 8601 date (added in PHP 5)
      self::$date_format = 'c';
    self::$verbose_file_name = $settings['logfile'];
    if (strtolower(substr(self::$verbose_file_name, 0, strlen(SYSLOG_PREFIX))) == SYSLOG_PREFIX) {
      $facility = substr(self::$verbose_file_name, strlen(SYSLOG_PREFIX));     //  syslog://[facility]
      self::$syslog_facility = defined($facility) ? constant($facility) : LOG_LOCAL0;
      self::$syslog_id = $settings['syslog_id'];
    }
    if (!is_string($settings['verbose'])) {
      self::$verbose_mask = (empty($settings['verbose']) ? 0 : $settings['level']);
    }
    else {
      foreach (explode('+', $settings['verbose']) as $vm) {
        if (defined(trim($vm)))
          self::$verbose_mask |= constant(trim($vm));
      }
    }
    if (strtolower($settings['logstyle']) == 'string') {
      self::$style = 'string';
    }
    self::$ip = $_SERVER['REMOTE_ADDR'];
  }

  /**
   * \brief Logs to a file, or prints out log message.
   * @param integer $verbose_level Level of verbose output
   * @param mixed $mixed List of values to log
   */
  static public function log($verbose_level, $mixed) {
    $text_level = array(WARNING => 'WARNING', ERROR => 'ERROR', FATAL => 'FATAL', STAT => 'STAT', TIMER => 'TIMER', DEBUG => 'DEBUG', TRACE => 'TRACE', Z3950 => 'Z3950', OCI => 'OCI');
    if (self::$verbose_file_name && $verbose_level & self::$verbose_mask) {
      if (!$vtext = $text_level[$verbose_level]) $vtext = 'UNKNOWN';

      $log_arr = array_merge(self::log_defaults($vtext), is_array($mixed) ? $mixed : array('message' => $mixed));
      $log_str = (self::$style == 'string') ? self::stringify($log_arr) : json_encode($log_arr);
      $json_log = str_replace(PHP_EOL, '', $log_str) . PHP_EOL;
      if (self::$syslog_facility) {
        openlog(self::$syslog_id, LOG_ODELAY, self::$syslog_facility);
        syslog(LOG_INFO, $json_log);
        closelog();
      }
      elseif ($fp = @ fopen(self::$verbose_file_name, 'a')) {
        fwrite($fp, $json_log);
        fclose($fp);
      }
      else
        die('FATAL: Cannot open ' . self::$verbose_file_name . "getcwd:" . getcwd());
    }
  }

  static private function stringify($arr) {
    $ret = array();
    foreach ($arr as $key => $val) {
      $ret[] = $key . ':' . $val;
    }
    return implode(' ', $ret);
  }

  /**
   * \brief Set array with default logging info
   * @param string $level loglevel
   * @return array
   */
  static private function log_defaults($level) {
    return array('level' => $level, 
                 'timestamp' => date(self::$date_format), 
                 'trackingId' => self::$tracking_id, 
                 'action' =>  self::$action,
                 'ip' =>  self::$ip);
  }

  static public function set_action($action) {
    self::$action = $action;
  }

  /**
   * \brief Make a unique tracking id
   * @param string $t_service_prefix Service prefix that identifies the service
   * @param string $t_id Current tracking_id
   * @return string
   */
  static public function set_tracking_id($t_service_prefix, $t_id = '') {
    self::$tracking_id = $t_service_prefix . ($t_service_prefix ? ':' : '') . self::$tracking_id . ($t_id ? '<' . $t_id : '');
    return self::$tracking_id;
  }

}
