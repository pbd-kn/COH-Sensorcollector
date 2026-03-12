<?php

namespace PbdKn\cohSensorcollector;

class mysql_dialog {

  var $msg1="Not connected to MySQL Server! Please check your connection data or call function \"connect()\" first";
  var $msg2="Please check your SQL statement or call function \"speak()\" first!";

  var $errors="";
  var $rows="";
  var $fields="";
  var $printerror=false;
  var $error_id=false;
  var $con=false;
  var $sql_id;

  function mysql_dialog($mode=false) {
    if ($mode){
      $this->printerror=true;
    }
  }

  function connect($host=false, $user=false, $pass=false, $dbname=false) {

    $con = new \mysqli($host, $user, $pass, $dbname);

    if ($con->connect_errno) {
        $this->errors = "MySQL connect error ({$con->connect_errno}): {$con->connect_error}";
        return false;
    }

    $this->con=$con;
    $con->set_charset("utf8mb4");

    return $this->con;
  }

  function close() { 
    if ($this->con) {
      $this->con->close();
      unset ($this->sql_id);
    }
  }
  
  function getConnection() {
    return $this->con;
  }

  function set_charset($charset) {
    if ($this->con) {
      return $this->con->set_charset($charset);
    }
    return false;
  }

  function execute ($sql=false) {
    return $this->speak($sql);
  }

  function query ($sql=false) {
    return $this->speak($sql);
  }

  function speak($sql=false) {

    if (!$this->con) {
      $this->error_id=$this->msg1;
      $this->makeerror();
      return false;
    }

    if(!$sql_id = $this->con->query($sql)){
      $this->errors = $this->con->error;
      return false;
    }

    $this->sql_id=$sql_id;

    if (!$this->sql_id) {
      $this->makeerror();
    }

    return $this->sql_id;
  }

function listen(string $type = 'assoc') {

    if (!$this->con) {
        $this->error_id = $this->msg1;
        $this->makeerror();
        return false;
    }

    if (!$this->sql_id) {
        $this->error_id = $this->msg2;
        $this->makeerror();
        return false;
    }

    $data = [];

    switch (strtolower($type)) {
        case 'assoc':
            while ($row = $this->sql_id->fetch_assoc()) {
                $data[] = $row;
            }
            break;

        case 'num':
            while ($row = $this->sql_id->fetch_row()) {
                $data[] = $row;
            }
            break;

        case 'both':
            while ($row = $this->sql_id->fetch_array(MYSQLI_BOTH)) {
                $data[] = $row;
            }
            break;

        default:
            while ($row = $this->sql_id->fetch_assoc()) {
                $data[] = $row;
            }
            break;
    }

    $this->rows = $this->sql_id->num_rows;
    $this->fields = $this->sql_id->fetch_fields();

    return $data;
}

  function ntuples() {
    if (!$this->con) return 0;
    if (!$this->sql_id) return 0;
    return ($this->sql_id->num_rows);
  }

  function affected() {
    if (!$this->con) return 0;
    return ($this->con->affected_rows);
  }

  function prepare($sql) {
    if (!$this->con) return false;
    return $this->con->prepare($sql);
  }

  function begin_transaction() {
    if ($this->con) return $this->con->begin_transaction();
    return false;
  }

  function commit() {
    if ($this->con) return $this->con->commit();
    return false;
  }

  function rollback() {
    if ($this->con) return $this->con->rollback();
    return false;
  }

  function __get($name) {
    if ($this->con && property_exists($this->con, $name)) {
        return $this->con->$name;
    }
    return null;
  }

  function makeerror() {

    if (!$this->error_id) {

      if ($this->con && $this->con->errno ){          
        $result="<b>" .$this->con->errno. " :<font color=\"red\">" . $this->con->error. "</font></b><br>";
        $this->errors=$result;

        if ($this->printerror){
          echo $result;
        }

        return $result;
      }

    } else {

      $result="<b><font color=\"red\">$this->error_id</font></b><br>";
      $this->errors=$result;

      if ($this->printerror){
        echo $result;
      }

      return $result;
    }

    return "";
  }

}