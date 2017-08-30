<?php

  /**********************************************************
  *
  * (c)2010 Webuddha, Holodyn Corporation
  *
  *   Database Class for WHMCS AddOns
  *
  *     new wbDatabase()
  *
  *     Public Methods
  *       Object    -> _construct( $config=array() )
  *       String    -> getCfgVal( $key )
  *       Object    -> getInstance()
  *       null      -> close_dbh()
  *       Instance  -> &runQuery( $query )
  *       Instance  -> &runQueries( $queries )
  *       Instance  -> runInsert( $tblName, $data, $xtra=null, $ignore=false )
  *       Instance  -> runUpdate( $tblName, $data, $where, $xtra=null )
  *       Array     -> getRow( $item=0 )
  *       Array     -> getRows( $start=null, $limit=null )
  *       Object    -> getObject( $item=0 )
  *       Array     -> getObjects( $start=null, $limit=null )
  *       String    -> getValue( $field )
  *       Integer   -> getRowCount()
  *       Array     -> getFields( $tblName )
  *       Integer   -> getNextID( $tblName )
  *       Integer   -> getLastID()
  *       String    -> getErrMsg()
  *       Number    -> getErrNum()
  *       String    -> getEscaped( $str )
  *       String    -> getNullDate()
  *       Boolean   -> isNullDate( $val )
  *
  *     Private Methods
  *       null      -> __sleep()
  *       null      -> __wakeup()
  *       null      -> __connect()
  *       null      -> _throwError( $msg=null, $num=400 )
  *
  *     Environment Methods
  *       null      -> __construct_whmcs()
  *       null      -> __connect_whmcs()
  *
  *** CHANGELOG *********************************************
  *
  * v1.0.0 - Upgraded Class from whmcs_dbh
  * v1.1.0 - Updated for WHMCS v5.1.x series
  * v1.1.1 - Updated Charset Processing
  * v2.0.0 - Implemented MySQLi
  * v2.0.1 - Updated getRows() function
  * v2.0.2 - Upgrade Globals
  * v2.0.3 - Update global usage to preven PHP Notice
  * v2.0.4 - Added WHMCS definition to validation
  * v2.1.0 - Force MySQLi connection if type undefined
  *        - Add failover on persistent connection error
  * v2.1.1 - Replaced DS with DIRECTORY_SEPARATOR
  * v2.1.2 - Added PHP v5.3.0 requirement to MySQLi persistent connection
  *        - Add failover on persistent connection error
  * v2.1.3 - Corrected warning with isNullDate "Empty Needle"
  * v2.2.0 - Added table prefix translation `#__`
  *        - Added setCfgVal( $key, $value )
  *        - Isolated WHMCS Initialization
  * v2.2.1 - Added port validation
  * v2.3.0 - Added ping confirmation to getInstance() call
  * v2.4.0 - Added runQueries() method
  * v2.4.1 - Corrected issues with runQueries() method
  * v2.5.0 - Added __connect / __sleep / __wakeup events
  * v2.5.1 - Patched __connect to adopt static if same
  * v2.5.2 - Patched usage of global $whmcsmysql
  * v2.6.0 - Implement PDO connector
  * v2.6.1 - Patch PDO use of WHMCS connector, getEscaped operation
  * v3.0.0 - Modified runQuery, runQueries to returns self object
  *        - Added getObject, getObjects methods
  * v3.0.1 - Patch runQueries PDO implementation
  * v3.1.0 - Added `created` config value to track age and compare on wakeup
  * v3.2.0 - Added $_version definition and getVersion() method
  * v3.2.1 - Changed default connector to PDO before MySQLi
  *
  **********************************************************/

defined('WHMCS') or defined('WHMCS_ADMIN') or defined('WHMCS_CLIENT') or die('Invalid Access');

class wbDatabase {

  /**********************************************************
  *
  **********************************************************/
  private static $_version  = '3.2.1';
  private static $_instance = null;
  public $_dbh              = null;
  private $_driver          = 'pdo';
  private $_config          = null;
  private $_query           = null;
  private $_errorMsg        = null;
  private $_errorNum        = null;
  private $_result          = null;
  private $_result_cache    = null;
  private $_result_index    = null;
  private $_nullDate        = '0000-00-00 00:00:00';

  /**********************************************************
  *
  **********************************************************/
  public function __construct( $config=array() ) {

    // Default Config
      $this->_config = array(
        'type'    => (class_exists('pdo', false) ? 'pdo' : (class_exists('mysqli',false) ? 'mysqli' : null)),
        'port'    => null,
        'host'    => null,
        'name'    => null,
        'user'    => null,
        'pass'    => null,
        'hash'    => null,
        'encode'  => null,
        'persist' => null,
        'prefix'  => null,
        'created' => time()
        );

    // Overwrite Config
      if( is_array($config) ){
        foreach( $config AS $k => $v ){
          if( array_key_exists($k,$this->_config) && $this->_config[$k] != $v ){
            $this->_config[$k] = $v;
          }
        }
      }

    // Detect Environment
      foreach( get_class_methods($this) AS $method ){
        if( preg_match('/__construct_/', $method) ){
          $this->{$method}();
        }
      }

    // Validate Port
      $this->_config['port'] = (int)$this->_config['port'] ? $this->_config['port'] : null;

    // Connect
      $this->__connect();

    // Store Instance
      if( is_null(self::$_instance) )
        self::$_instance =& $this;

  } // ->__construct

  /**********************************************************
  *
  **********************************************************/
  private function __construct_whmcs(){

    // Import Values
      global $whmcsmysql, $cc_encryption_hash, $mysql_charset;
      global $db_type, $db_port, $db_host, $db_name, $db_username, $db_password;
      if( !defined('ROOTDIR') ){
        $_tmp = explode(DIRECTORY_SEPARATOR, dirname(__FILE__));
        for($i=0;$i<2;$i++) array_pop($_tmp);
        $ROOTDIR = implode(DIRECTORY_SEPARATOR,$_tmp); unset($_tmp);
      } else
        $ROOTDIR = ROOTDIR;
      if( file_exists($ROOTDIR.DIRECTORY_SEPARATOR.'configuration.php') )
        require($ROOTDIR.DIRECTORY_SEPARATOR.'configuration.php');

    // Default Config
      if (@$db_type) $this->setCfgVal( 'type', $db_type );
      if (@$db_port) $this->setCfgVal( 'port', $db_port );
      $this->setCfgVal( 'host',   $db_host );
      $this->setCfgVal( 'name',   $db_name );
      $this->setCfgVal( 'user',   $db_username );
      $this->setCfgVal( 'pass',   $db_password );
      $this->setCfgVal( 'hash',   $cc_encryption_hash );
      $this->setCfgVal( 'encode', isset($mysql_charset) ? $mysql_charset : '' );

  }

  /**********************************************************
  *
  **********************************************************/
  private function __connect(){

    // Already Connected?
      if( $this->_dbh ){
        return;
      }

    // Adopt Static Instance
      if( self::$_instance && self::$_instance->_dbh ){
        if( md5(serialize(static::$_instance->_config)) == md5(serialize($this->_config)) ){
          foreach( array('_driver', '_nullDate', '_dbh') AS $key ){
            $this->{ $key } = static::$_instance->{ $key };
          }
          return;
        }
      }

    // Persistent Connection
      $persist = !is_null($this->_config['persist']) ? $this->_config['persist'] : false;

    // Detect Environment
      foreach( get_class_methods($this) AS $method ){
        if( preg_match('/__connect_/', $method) ){
          $this->{$method}();
        }
      }

    // Connected by Environment
      if( $this->_dbh ){
        return;
      }

    // PDO
      ob_start();
      if( (empty($this->_config['type']) || strpos($this->_config['type'], 'pdo') === 0) && class_exists('pdo', false) ){
        $this->_driver = 'pdo';
        $this->_nullDate = '0000-00-00 00:00:00';
        $PDODriver = strpos($this->_driver, ':') !== false ? end(explode(':', $this->_driver)) : 'mysql';
        $PDOConnectString = $PDODriver
                          . ':host=' . $this->_config['host']
                          . ';dbname=' . $this->_config['name']
                          . (strlen($this->_config['encode']) ? ';charset=' . $this->_config['encode'] : '')
                          ;
        $PDOFlags = array(
          PDO::ATTR_EMULATE_PREPARES => true,
          PDO::ATTR_PERSISTENT       => $persist,
          PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION
          );
        try {
          $this->_dbh = new PDO(
                          $PDOConnectString,
                          $this->_config['user'],
                          $this->_config['pass'],
                          $PDOFlags
                        );
        } catch (PDOException $e) {
          $this->_throwError('Unable to connect to database: ' . $e->getMessage(), 500);
        }
      }

    // MySQLi
      else if( (empty($this->_config['type']) || strpos($this->_config['type'], 'mysqli') === 0) && class_exists('mysqli',false) ){
        $persist = version_compare(PHP_VERSION, '5.3.0', '>=') ? $persist : false;
        $this->_driver = 'mysqli';
        $this->_nullDate = '0000-00-00 00:00:00';
        $this->_dbh = new mysqli(
            ($persist ? 'p:' : '') . $this->_config['host'],
            $this->_config['user'],
            $this->_config['pass'],
            $this->_config['name'],
            $this->_config['port']
          );
        if( $persist && !is_null($this->_dbh) && $this->_dbh->connect_error )
          $this->_dbh = new mysqli(
              $this->_config['host'],
              $this->_config['user'],
              $this->_config['pass'],
              $this->_config['name'],
              $this->_config['port']
            );
        if( !$this->_dbh->connect_error ){
          // Character Encoding
          if( strlen($this->_config['encode']) )
            $this->runQuery("
              SET character_set_client = '". $this->getEscaped($this->_config['encode']) ."'
              , character_set_connection = '". $this->getEscaped($this->_config['encode']) ."'
              , character_set_results = '". $this->getEscaped($this->_config['encode']) ."'
              ");
        } else
          $this->_throwError('Unable to connect to database: ('.mysqli_connect_errno().') '.mysqli_connect_error(),500);
      }

    // ELSE MySQL
      else {
        $this->_driver = 'mysql';
        $this->_nullDate = '0000-00-00 00:00:00';
        if( $persist )
          $this->_dbh = mysql_pconnect(
            $this->_config['host'] . ($this->_config['port'] ? ':'.$this->_config['port'] : ''),
            $this->_config['user'],
            $this->_config['pass']
            );
        if( !$persist || is_null($this->_dbh) )
          $this->_dbh = mysql_connect(
            $this->_config['host'] . ($this->_config['port'] ? ':'.$this->_config['port'] : ''),
            $this->_config['user'],
            $this->_config['pass'],
            true);
        if( $this->_dbh ){
          if( !is_resource($GLOBALS['whmcsmysql']) || $this->_dbh !== $GLOBALS['whmcsmysql'] ){
            // Select Database
            mysql_select_db(
              $this->_config['name'],
              $this->_dbh
              ) or $this->_throwError('Unable to select database',500);
            // Character Encoding
            if( strlen($this->_config['encode']) && function_exists('mysql_set_charset') )
              mysql_set_charset($this->_config['encode'], $this->_dbh);
          }
        } else
          $this->_throwError('Unable to connect to database',500);
      }
      ob_end_clean();

  }

  /**********************************************************
  *
  **********************************************************/
  public function __connect_whmcs(){

    // Stage Global
      global $whmcsmysql;

    // PDO
      if(
        (empty($this->_config['type']) || strpos($this->_config['type'], 'pdo') === 0)
        && class_exists('Illuminate\Database\Capsule\Manager')
        && class_exists('pdo', false)
        ){
        $pdo = Illuminate\Database\Capsule\Manager::connection()->getPdo();
        if ($pdo instanceof PDO) {
          $this->_dbh = $pdo;
          $this->_driver = 'pdo';
        }
      }

    // MySQL
      if(
        empty($this->_dbh)
        && is_resource($whmcsmysql)
        && $this->getCfgVal('persist') == true
        && $this->getCfgVal('type') == 'mysql'
        ){
        $this->_dbh = $whmcsmysql;
        $this->_driver = 'mysql';
      }

  }

  /**********************************************************
  *
  **********************************************************/
  public function __sleep(){
    return array(
      '_config'
      );
  }

  /**********************************************************
  *
  **********************************************************/
  public function __wakeup(){
    // We're old, let's update to latest environment
    if (empty($this->_config['created']) || ($this->_config['created'] < (time() - 86400))) {
      $this->__construct(array_diff_key($this->_config, array('created' => true)));
    }
    else {
      $this->__connect();
    }
  }

  /**********************************************************
  *
  **********************************************************/
  public function setCfgVal( $key, $value ) {
    $this->_config[ $key ] = $value;
    return $this;
  } // ->setCfgVal

  /**********************************************************
  *
  **********************************************************/
  public function getCfgVal( $key ) {
    return $this->_config[ $key ];
  } // ->getCfgVal

  /**********************************************************
  *
  **********************************************************/
  public static function getVersion(){
    return self::$_version;
  }

  /**********************************************************
  *
  **********************************************************/
  public static function &getInstance() {

    // Ping to confirm connection remains
      if( self::$_instance instanceof self ){
        if( self::$_instance->_driver == 'pdo' ){
          try {
            self::$_instance->_dbh->query('SELECT 1');
            $res = true;
          } catch (PDOException $e) {
            $res = false;
          }
        }
        else if( self::$_instance->_driver == 'mysqli' )
          $res = mysqli_ping(self::$_instance->_dbh);
        else
          $res = mysql_ping(self::$_instance->_dbh);
        if( !$res )
          self::$_instance = null;
      }

    // Establish Instance
      if( is_null(self::$_instance) )
        self::$_instance = new wbDatabase();

    // Return
      return self::$_instance;

  } // ->getInstance

  /**********************************************************
  *
  **********************************************************/
  public function close_dbh() {
    if( $this->_driver == 'pdo' )
      $this->_dbh = null;
    else if( $this->_driver == 'mysqli' )
      mysqli_close($this->_dbh);
    else
      mysql_close($this->_dbh);
  } // ->close_dbh

  /**********************************************************
  *
  **********************************************************/
  public function &runQuery( $query, $data = array() ){
    $this->_query = trim( $query );
    if( strlen($this->_query) ){
      $this->_result = null;
      $this->_result_cache = null;
      $this->_result_index = 0;
      if( $this->_driver == 'pdo' ){
        try {
          $this->_result = $this->_dbh->prepare( $this->_query );
          $this->_result->execute( $data );
          if ($this->_result === false)
            $this->_throwError();
        } catch (Exception $e) {
          $this->_throwError( $e->getMessage() );
        }
      }
      else if( $this->_driver == 'mysqli' ){
        if( is_resource($this->_result) )
          mysqli_free_result( $this->_result );
        $this->_result = mysqli_query($this->_dbh, $this->_query);
        if( !$this->_result )
          $this->_throwError();
      }
      else {
        if( is_resource($this->_result) )
          mysql_free_result( $this->_result );
        $this->_result = mysql_query($this->_query, $this->_dbh);
        if( !$this->_result )
          $this->_throwError();
      }
    }
    else {
      $this->_throwError('Invalid Query');
    }
    return $this;
  } // ->runQuery

  /**********************************************************
  *
  **********************************************************/
  public function &runQueries( $queries ){
    $queries = is_array($queries) ? $queries : (is_string($queries) ? array($queries) : array());
    if( @$queries ){
      if( $this->_driver == 'pdo' ){
        $this->_query = implode(";\n", $queries) . ';';
        try {
          $this->_result = $this->_dbh->exec( $this->_query );
          if ($this->_result === false)
            $this->_throwError();
        } catch (Exception $e) {
          $this->_throwError( $e->getMessage() );
        }
      }
      else if( $this->_driver == 'mysqli' ){
        if( is_resource($this->_result) )
          mysqli_free_result( $this->_result );
        $this->_query = implode(";\n", $queries) . ';';
        if( $this->_result = mysqli_multi_query($this->_dbh, $this->_query) ){
          $this->_result_cache = null;
          do {
            if( $result = mysqli_store_result($this->_dbh) ){
              while( $row = mysqli_fetch_row($result) ){}
              mysqli_free_result($result);
            }
          } while( @mysqli_next_result($this->_dbh) );
        }
        if( !$this->_result )
          $this->_throwError();
      }
      else {
        foreach( $queries AS $query ){
          $this->runQuery($query);
        }
      }
    }
    else {
      $this->_throwError('Invalid Query');
    }
    return $this;
  } // ->runQueries

  /**********************************************************
  *
  **********************************************************/
  public function runInsert( $tblName, $data, $xtra=null, $ignore=false ){
    $keys = $vals = array();
    foreach( $data AS $k=>$v ){
      $keys[] = $this->getEscaped($k);
      $vals[] = is_null($v) ? 'NULL' : "'" . $this->getEscaped($v) . "'";
    }
    return $this->runQuery("INSERT ".($ignore?'IGNORE':'')." INTO `". $this->getEscaped($tblName) ."` (`". implode('`,`',$keys) ."`) VALUES (". implode(',',$vals) .") ". $xtra);
  } // ->runInsert

  /**********************************************************
  *
  **********************************************************/
  public function runUpdate( $tblName, $data, $where, $xtra=null ){
    if( !count($where) )
      $this->_throwError('Missing Where Filters for Update Query',500);
    $pairs = array();
    foreach( $data AS $k=>$v )
      $pairs[] = "`".$this->getEscaped($k)."` = " . (is_null($v) ? 'NULL' : "'" . $this->getEscaped($v) . "'");
    return $this->runQuery("UPDATE `". $this->getEscaped($tblName) ."` SET ". implode(', ',$pairs) ." WHERE ". implode(' AND ',$where) . ' ' . $xtra);
  } // ->runUpdate

  /**********************************************************
  *
  **********************************************************/
  public function getRow( $item = null ) {
    $num  = $this->getRowCount();
    $row  = Array();
    if( $this->_driver == 'pdo' ){
      if (empty($this->_result_cache))
        $this->_result_cache = $this->_result->fetchAll(PDO::FETCH_ASSOC);
      if (!is_null($item))
        $this->_result_index = (int)$item;
      return $this->_result_cache[ $this->_result_index++ ];
    }
    else if( $this->_driver == 'mysqli' ){
      if( !is_null($item) && mysqli_data_seek($this->_result, $item) === false )
        return $row;
      return mysqli_fetch_assoc($this->_result);
    } else {
      if( !is_null($item) && mysql_data_seek($this->_result, $item) === false )
        return $row;
      return mysql_fetch_assoc($this->_result);
    }
  } // ->getRow

  /**********************************************************
  *
  **********************************************************/
  public function getRows( $start = null, $limit = null ) {
    $num  = $this->getRowCount();
    $rows = Array();
    if( $this->_driver == 'pdo' ){
      if (empty($this->_result_cache))
        $this->_result_cache = $this->_result->fetchAll(PDO::FETCH_ASSOC);
      for( $i=0; $i<($num-(is_null($start)?0:$start)); $i++ )
        if( is_null($limit) || count($rows) < $limit )
          $rows[] = @$this->_result_cache[ $i ];
      return $rows;
    }
    else if( $this->_driver == 'mysqli' ){
      if( !is_null($start) && mysqli_data_seek($this->_result, $start) === false )
        return $rows;
      for( $i=0; $i<($num-(is_null($start)?0:$start)); $i++ )
        if( is_null($limit) || count($rows) < $limit )
          $rows[] = mysqli_fetch_assoc($this->_result);
      return $rows;
    } else {
      if( !is_null($start) && mysql_data_seek($this->_result, $start) === false )
        return $rows;
      for( $i=0; $i<($num-(is_null($start)?0:$start)); $i++ )
        if( is_null($limit) || count($rows) < $limit )
          $rows[] = mysql_fetch_assoc($this->_result);
      return $rows;
    }
  } // ->getRows

  /**********************************************************
  *
  **********************************************************/
  public function getObject( $item = null ){
    $res = $this->getRow( $item );
    return $res ? (object)$res : $res;
  }

  /**********************************************************
  *
  **********************************************************/
  public function getObjects( $start = null, $limit = null ){
    $rows = $this->getRows( $start, $limit );
    if ($rows) {
      foreach ($rows AS &$row) {
        $row = (object)$row;
      }
    }
    return $rows;
  }

  /**********************************************************
  *
  **********************************************************/
  public function getValue( $field = null ) {
    $row = null;
    if( $this->getRowCount() > 0 ){
      $row = $this->getRow();
      if ($row && count(array_keys($row))) {
        if (!is_null($field))
          return $row[$field];
        $keys = array_keys($row);
        return $row[ $keys[0] ];
      }
    }
    return null;
  } // ->getValue

  /**********************************************************
  *
  **********************************************************/
  public function getRowCount() {
    if( $this->_driver == 'pdo' )
      return method_exists($this->_result, 'rowCount') ? $this->_result->rowCount() : 0;
    else if( $this->_driver == 'mysqli' )
      return mysqli_num_rows($this->_result);
    else
      return mysql_numrows($this->_result);
  } // ->getRowCount

  /**********************************************************
  *
  **********************************************************/
  public function getFields( $tblName ) {
    $this->runQuery('SHOW COLUMNS FROM `'.$this->getEscaped($tblName).'`');
    $rows = $this->getRows(); $fields = Array();
    foreach( $rows AS $row ) $fields[] = $row['Field'];
    return $fields;
  } // ->getFields

  /**********************************************************
  *
  **********************************************************/
  public function getNextID( $tblName ) {
    $this->runQuery("SHOW TABLE STATUS LIKE '". $this->getEscaped($tblName) ."'");
    return $this->getValue('Auto_increment');
  } // ->getNextID

  /**********************************************************
  *
  **********************************************************/
  public function getLastID() {
    if( $this->_driver == 'pdo' )
      return $this->_dbh->lastInsertId();
    else if( $this->_driver == 'mysqli' )
      return mysqli_insert_id( $this->_dbh );
    else
      return mysql_insert_id( $this->_dbh );
  } // ->getLastID

  /**********************************************************
  *
  **********************************************************/
  public function getErrMsg() {
    return (string)$this->_errorMsg;
  } // ->getErrMsg

  /**********************************************************
  *
  **********************************************************/
  public function getErrNum() {
    return (int)$this->_errorNum;
  } // ->getErrNum

  /**********************************************************
  *
  **********************************************************/
  public function getEscaped( $str ) {
    if( $this->_driver == 'pdo' )
      return preg_replace( '/^\'|\'$/', '', $this->_dbh->quote( $str ) );
    else if( $this->_driver == 'mysqli' )
      return (string)mysqli_real_escape_string( $this->_dbh, $str );
    else
      return (string)mysql_real_escape_string( $str, $this->_dbh );
  } // ->getEscaped

  /**********************************************************
  *
  **********************************************************/
  public function getNullDate(){
    return $this->_nullDate;
  } // ->getNullDate

  /**********************************************************
  *
  **********************************************************/
  public function isNullDate( $val ){
    if( is_null($val) || (string)$val == (string)$this->_nullDate )
      return true;
    return false;
  } // ->isNullDate

  /**********************************************************
  *
  **********************************************************/
  private function _throwError( $msg=null, $num=400 ){
    if( !is_null($msg) ){
      $this->_errorMsg = 'wbDatabase:msg'.$msg;
      $this->_errorNum = $num;
      if($this->_errorNum < 500){
        echo '<div class="error_msg">'. $this->_errorMsg .'</div>';
        return;
      }
    }
    else if( $this->_driver == 'pdo' ){
      $this->_errorMsg = $this->_dbh->errorInfo()[2];
      $this->_errorNum = $this->_dbh->errorCode();
    }
    else if( $this->_driver == 'mysqli' ){
      $this->_errorMsg = mysqli_error( $this->_dbh );
      $this->_errorNum = mysqli_errno( $this->_dbh );
    }
    else {
      $this->_errorMsg = mysql_error( $this->_dbh );
      $this->_errorNum = mysql_errno( $this->_dbh );
    }
    die("<pre>\n" . print_r(array(
      'wbDatabase fatal error',
      'errno: ' . $this->_errorNum,
      'errmsg: ' . $this->_errorMsg
      ), true));
  } // ->_throwError

} // class-wbDatabase

/**********
  END
***********/
