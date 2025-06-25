<?php

namespace PbdKn\cohSensorcollector;

/***
**** @class: mysql_dialog
**** @version: 1.4;
**** @author: Giorgos Tsiledakis;
**** @date: 2004-08-25;
**** @license: GNU GENERAL PUBLIC LICENSE;

**** Standard für Zugriffe auf die mySQL Datenbank

***/
//include_once('mysql_dialog.php');    /Fuer db-zugriffe schreibt evtl direkt in die Contao-db


class mysql_dialog {
  var $msg1="Not connected to MySQL Server! Please check your connection data or call function \"connect()\" first";
  var $msg2="Please check your SQL statement or call function \"speak()\" first!";

  var $errors=""; // the last error occured;
  var $rows="";  // number of rows of the query, created by listen();
  var $fields=""; // number fields of the query, created by listen();
  var $printerror=false;
  var $error_id=false;
  var $con=false;
  var $sql_id;

  /*##
    #### Call first Class Constructor mysql_dialog() to beginn;
    #### If some value!=0 is passed to mysql(),
    #### the errors occured, after each function is called, will be printed in the main script
    ##*/
  function mysql_dialog($mode=false) {
    if ($mode){
      $this->printerror=true;
    }
  }

  /*##
    #### Call then connect("mysqlhost","mysqluser","mysqlpasswd","name of mysql database")
    #### it returns some public $con or creates errors;
    ##*/
  function connect($host=false, $user=false, $pass=false, $dbname=false) {
    $con = new \mysqli($host, $user, $pass, $dbname);
    //echo "make connection <br>";
    if ($con->connect_errno) {
    echo "Failed to connect to MySQL: (" . $con->errno . ") ".  $con->connect_error;
    return false;
    }
    $this->con=$con;
    \mysqli_set_charset ( $con , "utf8" );

    return $this->con;
  }
  function close() { 
    if ($this->con) {
//echo "<br>close connection<br>";
      $this->con->close();
      //$sql_id=false;
      unset ($sql_id);
    }
    }
  
  function getConnection() {
    return $this->con;
  }

  /* wrapper */
  function execute ($sql=false) {
    return $this->speak($sql);
  }
  function query ($sql=false) {
    return $this->speak($sql);
  }
  /*##

    #### Call speak("SQL STRING") to send some sql query to the database;
    #### it returns some public $sql_id, or creates errors;
    ##*/
  function speak($sql=false) {
  //echo "speak $sql <br>\n";
    if (!$this->con) {
      $this->error_id=$this->msg1;
      $this->makeerror();
      return false;
    }
    if (isset($this->sql_id)) {
      //$this->sql_id->free();
    }
    //echo "<br> hallo $sql <br>";
    if(!$sql_id = $this->con->query($sql)){    // mach den query
    //die('There was an error running the query [' . $db->error . ']');
      echo "query fehlerhaft " . $this->con->error;
      return false;
    }
    $this->sql_id=$sql_id;
    if (!$this->sql_id) {
      $this->makeerror();
    }
    return $this->sql_id;
  }

  /*##
    #### Call listen() to get the result of the query;
    #### it returns an array with the results of the query, or creates errors;
    #### listen() must be called after speak("SQL STRING") was called;
    ####  assoc nur asoziative Indizes (genau wie bei mysql_fetch_assoc()).
    ####  num nur numerische Indizes (genau wie bei mysql_fetch_row()).
    ####  both beides numerische Indizes (genau wie bei mysql_fetch_row()).
    ##
    ## bei msqli wird Both nicht unterstützt !!

    ##*/
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
    //$data=@mysql_fetch_array($this->sql_id, MYSQL_NUM);
    return ($this->sql_id->num_rows);
  }
  function affected() {
    if (!$this->con) return 0;
    if (!$this->sql_id) return 0;
    return ($this->con->affected_rows);
  }


  /*##
    #### Call onscreen("SQL STRING") to print a table with the result of the query;
    ##*/
  function onscreen($sql=false) {
    $this->speak($sql);
    echo ("<table border=\"1\" cellpadding=\"4\"><tr>");
    while ($fields=$this->fields=@$this->sql_id->fetch_fields()) {
      echo ("<th align=\"left\">$fields->name</th>");
    }
    echo ("</tr>\n");
    while ($rows = $this->listen(MYSQL_NUM)) {
      echo ("<tr>");
      for ($x=0; $x<@$this->sql_id->field_count; $x++) {
        echo ("<td align=\"left\">".htmlentities($rows[$x])."</td>");
      }
      echo ("</tr>\n");
    }
    echo ("</table>");
    }

  /*##
    #### Function makeerror() is called whenever some error has occured;
    #### If there is any error_id, it returns the user specified messages $msg1, $msg2,
    #### else it returns the mysql error number and message;
    #### If $printerror is true, the error message will be printed in the main script;
    ##*/
  function makeerror() {
    if (!$this->error_id) {
      if ($this->con->errno ){          
        $result="<b>" .$con->errno. " :<font color=\"red\">" . $con->error. "</font></b><br>";
        $this->errors=$result;
        if ($this->printerror){
          echo $result;
        }
        return $result;
        exit;
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
?>