<?php
 
function ormclass_collection($class_name, $array) {
  $ret_array = array();
  foreach ($array as $element) {
    $object      = new $class_name($element);
    $ret_array[] = $object;
  }
  return ($ret_array);
}
 
class ormclass extends stdClass {
  var $property_cache = array();
  var $property_types = array();
  var $a_rows, $rows, $result, $data, $id, $name;
  var $class_name;
  var $dblink;
 
  public function __construct($arg = null) {
    global $dbl;
    $this->dblink = $dbl;
    $this->class_name = get_class($this);
    $this->get_properties($this->class_name);
    if ($arg === 0) {
      $this->_create();
      if (method_exists($this,'creation')) $this->creation();
    } elseif (is_array($arg) === true) {
      return(ormclass_collection($this->class_name,$arg));
    } elseif (is_numeric($arg)) {
      $this->load_by_id($arg);
    } else {
      if (isset($this->property_types['name'])) $this->load_by_name($arg);
    }
    if (method_exists($this,'construct')) $this->construct();
  }
 
  public function search($property,$value,$limit = 10, $offset = 0) {
    $array  = array();
    if (!isset($this->property_types[$property])) return(null);
    if ($this->isa_string($property)) $value = $this->sanitize_string($value);
    else $value = abs((int)$value);
 
    if (isset($this->property_types[$property])) {
      $this->sql_query("select id from $this->class_name where $property LIKE '%$value%' order by id desc limit $limit offset $offset");
      for ($i = 0; $i < $this->rows; ++$i) {
        $this->sql_fetch($i);
        $array[] = $this->data['id'];
      }
      return(ormclass_collection($this->class_name, $array));
    }
  }
 
  public function search_exact($property,$value, $limit = 10, $offset = 0) {
    $array = array();
 
    if (!isset($this->property_types[$property])) return(null);
 
    if ($this->isa_string($property)) $value = "'" . $this->sanitize_string($value) . "'";
    else $value = abs((int)$value);
 
    $this->sql_query("select id from $this->class_name where $property=$value limit $limit offset $offset");
    for ($i = 0; $i < $this->rows; ++$i) {
      $this->sql_fetch($i);
      $array[] = abs((int)$this->data['id']);
    }
    return(ormclass_collection($this->class_name,$array));
  }
 
  public function unsafe_attr($field,$value) {
      if (strpos($value,"'") === false)     $this->sql_query("update $this->class_name set $field='$value'");
      elseif (strpos($value,'"') === false) $this->sql_query("update $this->class_name set $field=\x22$value\x22");
      else return false;
      $this->property_cache[$field] = $value;
  }
 
  public function fetchAll() {
    $this->sql_query("select id from $this->class_name");
    for ($i = 0; $i < $this->rows; ++$i) {
      $this->sql_fetch($i);
      $array[] = (int)$this->data['id'];
    }
    $ret = ormclass_collection($this->class_name,$array);
    return ($ret);
  }
 
  public function fetchRecent($limit = 10) {
    $limit = abs((int)$limit);
    $this->sql_query("select id from " . $this->class_name . " order by id desc limit $limit");
    for ($i = 0; $i < $this->rows; ++$i) {
      $this->sql_fetch($i);
      $array[] = (int)$this->data['id'];
    }
    $ret = ormclass_collection($this->class_name,$array);
    return ($ret);
  }
 
  public function delete() {
    $this->sql_query("delete from " . $this->class_name . " where id=" . $this->id);
  }
 
  public function save() {
    # The only thing that this cannot update is the database id; unless it was NEVER set,
   # then it creates one.
   $q_values = array();
 
    if (!$this->id && !isset($this->property_cache['id'])) $this->_create();
    if ($this->id != $this->property_cache['id']) return(null);
 
    foreach ($this->property_types as $property => $type) {
      if ($this->$property != $this->property_cache[$property])  {
        if ($property == 'id') next();
        if  ($this->isa_string($property) > 0) $q_values[] = $property . "='" . $this->sanitize_string($this->$property) ."'";
        elseif ($this->isa_int($property) > 0) $q_values[] = $property . '='  . abs((int)$this->$property);
      }
    }
    $query = "update $this->class_name set ";
    $query .= implode(',', $q_values);
    $query .= " where id=$this->id limit 1;";
    $this->sql_query($query);
  }
 
  public function _create() {
    $this->sql_query("insert into $this->class_name (id) values (null)");
    $this->id = $this->query_item("select last_insert_id();");
    $this->property_cache['id'] = $this->id;
  }
 
  private function isa_string($property) {
    if (strpos(strtolower($this->property_types[$property]),'char') === null) return(0);
    return(1);
  }
 
  private function isa_int($property) {
    if (strpos(strtolower($this->property_types[$property]),'int') === null) return(0);
    return(1);
  }
 
  private function get_properties($self) {
    $this->sql_query("select data_type,column_name from information_schema.columns where table_name='$self' and table_schema=database()");
    for ($i = 0; $i < $this->rows; ++$i) {
      $this->sql_fetch($i);
      $cname = $this->data['column_name'];
      $this->property_types[$cname] = $this->data['data_type'];
    }
  }
 
  private function load_by_id($id) {
    $this->id = abs((int)$id);
    $this->property_cache = $this->query_row("select * from $this->class_name where id=$this->id limit 1");
    $this->populate();
  }
 
  private function load_by_name($string) {
    $this->name = $this->sanitize_string($string);
    $this->property_cache = $this->query_row("select * from $this->class_name where name='$this->name' limit 1");
    $this->populate();
  }
 
  private function populate() {
    foreach ($this->property_types as $property => $type) {
      $this->$property = $this->data[$property];
    }
  }
 
  public function query_item($query) {
    $this->result = @mysql_query($query, $this->dblink);
    $data = @mysql_fetch_array($this->result);
    return ($data[0]);
  }
 
  public function query_row($query) {
    $this->result = @mysql_query($query, $this->dblink);
    $this->data = @mysql_fetch_array($this->result);
    return($this->data);
  }
 
  public function sql_fetch($row) {
    @mysql_data_seek($this->result, $row);
    $this->data = @mysql_fetch_array($this->result);
  }
 
  public function sql_query($query) {
    $this->result = @mysql_query($query, $this->dblink);
    $this->rows = @mysql_num_rows($this->result);
  }
 
  public function sql_insert($query) {
    $this->sqlResult = @mysql_query($query, $this->dblink);
  }
 
  public function sanitize_string($string) {
    $ret   = htmlentities($string, ENT_QUOTES, 'UTF-8');
    $lined = preg_replace('/\r?\n/','<br />',$ret);
    return ($lined);
  }
}
 
 
?>
