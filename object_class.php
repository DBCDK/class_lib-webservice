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


/**
 * \brief singleton class to handle object creation
 *
 * Usage: \n
 * Object::set_object_value(object, tag-to-set, value); \n
 * Object::set_object_namespace(object, tag-to-set, value); \n
 * Object::set_object_element(object, tag-to-set, element, value); \n
 *
 * Example:
 * Object::set_object_value($test, 'tag', 19);
 * Object::set_object_namespace($test, 'tag', 'string');
 * Object::set_object_element($test, 'tag', 'sub-tag', $var);
 *
 * @author Finn Stausgaard - DBC
**/

class Object {

  private function __construct() {}
  private function __destruct() {}
  private function __clone() {}

  /** \brief Sets _value on object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param value (mixed)
   **/
  static public function set_object_value(&$obj, $name, $value) {
    self::set_object_element($obj, $name, '_value', $value);
  }

  /** \brief Sets _namespace on object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param value (mixed)
   **/
  static public function set_object_namespace(&$obj, $name, $value) {
    self::set_object_element($obj, $name, '_namespace', $value);
  }

  /** \brief Sets an object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param value (mixed)
   **/
  static public function set_object(&$obj, $name, $value) {
    self::check_object_set($obj);
    $obj->$name = $value;
  }

  /** \brief Sets element on object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param element (string)
   * @param value (mixed)
   **/
  static public function set_object_element(&$obj, $name, $element, $value) {
    self::check_object_and_name_set($obj, $name);
    $obj->$name->$element = $value;
  }

  /** \brief makes surre the object is defined
   * @param obj (object) - the object to set
   * @param name (string)
   **/
  static private function check_object_and_name_set(&$obj, $name) {
    self::check_object_set($obj);
    self::check_object_set($obj->$name);
  }

  /** \brief makes surre the object is defined
   * @param obj (object) - the object to set
   **/
  static private function check_object_set(&$obj) {
    if (!isset($obj)) $obj = new stdClass();
  }

}
