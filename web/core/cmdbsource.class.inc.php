<?php
// Copyright (C) 2010-2013 Combodo SARL
//
//   This file is part of iTop.
//
//   iTop is free software; you can redistribute it and/or modify	
//   it under the terms of the GNU Affero General Public License as published by
//   the Free Software Foundation, either version 3 of the License, or
//   (at your option) any later version.
//
//   iTop is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU Affero General Public License for more details.
//
//   You should have received a copy of the GNU Affero General Public License
//   along with iTop. If not, see <http://www.gnu.org/licenses/>


/**
 * DB Server abstraction
 *
 * @copyright   Copyright (C) 2010-2012 Combodo SARL
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

require_once('MyHelpers.class.inc.php');
require_once(APPROOT.'core/kpi.class.inc.php');

class MySQLException extends CoreException
{
	public function __construct($sIssue, $aContext)
	{
		$aContext['mysql_error'] = CMDBSource::GetError();
		$aContext['mysql_errno'] = CMDBSource::GetErrNo();;
		parent::__construct($sIssue, $aContext);
	}
}


/**
 * CMDBSource
 * database access wrapper 
 *
 * @package     iTopORM
 */
class CMDBSource
{
	protected static $m_sDBHost;
	protected static $m_sDBUser;
	protected static $m_sDBPwd;
	protected static $m_sDBName;
	protected static $m_resDBLink;

	public static function Init($sServer, $sUser, $sPwd, $sSource = '')
	{
		self::$m_sDBHost = $sServer;
		self::$m_sDBUser = $sUser;
		self::$m_sDBPwd = $sPwd;
		self::$m_sDBName = $sSource;

		$aConnectInfo = explode(':', self::$m_sDBHost);
		if (count($aConnectInfo) > 1)
		{
			// Override the default port
			$sServer = $aConnectInfo[0];
			$iPort = $aConnectInfo[1];
			self::$m_resDBLink = @mysqli_connect($sServer, self::$m_sDBUser, self::$m_sDBPwd, '', $iPort);
		}
		else
		{
			self::$m_resDBLink = @mysqli_connect(self::$m_sDBHost, self::$m_sDBUser, self::$m_sDBPwd);
		}

		if (!self::$m_resDBLink)
		{
			throw new MySQLException('Could not connect to the DB server', array('host'=>self::$m_sDBHost, 'user'=>self::$m_sDBUser));
		}
		if (!empty($sSource))
		{
			if (!((bool)mysqli_query(self::$m_resDBLink, "USE `$sSource`")))
			{
				throw new MySQLException('Could not select DB', array('host'=>self::$m_sDBHost, 'user'=>self::$m_sDBUser, 'db_name'=>self::$m_sDBName));
			}
		}
	}

	public static function SetCharacterSet($sCharset = 'utf8', $sCollation = 'utf8_general_ci')
	{
		if (strlen($sCharset) > 0)
		{
			if (strlen($sCollation) > 0)
			{
				self::Query("SET NAMES '$sCharset' COLLATE '$sCollation'");
			}
			else
			{
				self::Query("SET NAMES '$sCharset'");
			}
		}
	}

	public static function SetTimezone($sTimezone = null)
	{
		// Note: requires the installation of MySQL special tables,
		//       otherwise, only 'SYSTEM' or "+10:00' may be specified which is NOT sufficient because of day light saving times
		if (!is_null($sTimezone))
		{
			$sQuotedTimezone = self::Quote($sTimezone);
			self::Query("SET time_zone = $sQuotedTimezone");
		}
	}

	public static function ListDB()
	{
		$aDBs = self::QueryToCol('SHOW DATABASES', 'Database');
		// Show Database does return the DB names in lower case
		return $aDBs;
	}

	public static function IsDB($sSource)
	{
		try
		{
			$aDBs = self::ListDB();
			foreach($aDBs as $sDBName)
			{
			// perform a case insensitive test because on Windows the table names become lowercase :-(
				if (strtolower($sDBName) == strtolower($sSource)) return true;
			}
			return false;
		}
		catch(Exception $e)
		{
			// In case we don't have rights to enumerate the databases
			// Let's try to connect directly
			return @((bool)mysqli_query(self::$m_resDBLink, "USE `$sSource`"));
		}

	}

	public static function GetDBVersion()
	{
		$aVersions = self::QueryToCol('SELECT Version() as version', 'version');
		return $aVersions[0];
	}
	
	public static function SelectDB($sSource)
	{
		if (!((bool)mysqli_query(self::$m_resDBLink, "USE `$sSource`")))
		{
			throw new MySQLException('Could not select DB', array('db_name'=>$sSource));
		}
		self::$m_sDBName = $sSource;
	}

	public static function CreateDB($sSource)
	{
		self::Query("CREATE DATABASE `$sSource` CHARACTER SET utf8 COLLATE utf8_unicode_ci");
		self::SelectDB($sSource);
	}

	public static function DropDB($sDBToDrop = '')
	{
		if (empty($sDBToDrop))
		{
			$sDBToDrop = self::$m_sDBName;
		}
		self::Query("DROP DATABASE `$sDBToDrop`");
		if ($sDBToDrop == self::$m_sDBName)
		{
			self::$m_sDBName = '';
		}
	}

	public static function CreateTable($sQuery)
	{
		$res = self::Query($sQuery);
		self::_TablesInfoCacheReset(); // reset the table info cache!
		return $res;
	}

	public static function DropTable($sTable)
	{
		$res = self::Query("DROP TABLE `$sTable`");
		self::_TablesInfoCacheReset(true); // reset the table info cache!
		return $res;
	}

	public static function GetErrNo()
	{
		if (self::$m_resDBLink)
		{
			return mysqli_errno(self::$m_resDBLink);
		}
		else
		{
			return mysqli_connect_errno();
		}
	}

	public static function GetError()
	{
		if (self::$m_resDBLink)
		{
			return mysqli_error(self::$m_resDBLink);
		}
		else
		{
			return mysqli_connect_error();
		}
	}

	public static function DBHost() {return self::$m_sDBHost;}
	public static function DBUser() {return self::$m_sDBUser;}
	public static function DBPwd() {return self::$m_sDBPwd;}
	public static function DBName() {return self::$m_sDBName;}

	public static function Quote($value, $bAlways = false, $cQuoteStyle = "'")
	{
		// Quote variable and protect against SQL injection attacks
		// Code found in the PHP documentation: quote_smart($value)

		// bAlways should be set to true when the purpose is to create a IN clause,
		// otherwise and if there is a mix of strings and numbers, the clause
		// would always be false

		if (is_null($value))
		{
			return 'NULL';
		}

		if (is_array($value))
		{
			$aRes = array();
			foreach ($value as $key => $itemvalue)
			{
				$aRes[$key] = self::Quote($itemvalue, $bAlways, $cQuoteStyle);
			}
			return $aRes;
		}

		// Stripslashes
		if (get_magic_quotes_gpc())
		{
			$value = stripslashes($value);
		}
		// Quote if not a number or a numeric string
		if ($bAlways || is_string($value))
		{
			$value = $cQuoteStyle . mysqli_real_escape_string(self::$m_resDBLink, $value) . $cQuoteStyle;
		}
		return $value;
	}

	public static function Query($sSQLQuery)
	{
		$oKPI = new ExecutionKPI();
		$result = mysqli_query(self::$m_resDBLink, $sSQLQuery);
		if (!$result) 
		{
			throw new MySQLException('Failed to issue SQL query', array('query' => $sSQLQuery));
		}
		$oKPI->ComputeStats('Query exec (mySQL)', $sSQLQuery);
	
		return $result;
	}

	public static function GetNextInsertId($sTable)
	{
		$sSQL = "SHOW TABLE STATUS LIKE '$sTable'";
		$result = self::Query($sSQL);
		$aRow = mysqli_fetch_assoc($result);
		$iNextInsertId = $aRow['Auto_increment'];
		return $iNextInsertId;
	}

	public static function GetInsertId()
	{
		$iRes = mysqli_insert_id(self::$m_resDBLink);
		if (is_null($iRes))
		{
			return 0;
		}
		return $iRes;
	}

	public static function InsertInto($sSQLQuery)
	{
		if (self::Query($sSQLQuery))
		{
			return self::GetInsertId();
		}
		return false;
	}

	public static function DeleteFrom($sSQLQuery)
	{
		self::Query($sSQLQuery);
	}

	public static function QueryToScalar($sSql)
	{
		$oKPI = new ExecutionKPI();
		$result = mysqli_query(self::$m_resDBLink, $sSql);
		$oKPI->ComputeStats('Query exec (mySQL)', $sSql);
		if (!$result)
		{
			throw new MySQLException('Failed to issue SQL query', array('query' => $sSql));
		}
		if ($aRow = mysqli_fetch_array($result, MYSQLI_BOTH))
		{
			$res = $aRow[0];
		}
		else
		{
			mysqli_free_result($result);
			throw new MySQLException('Found no result for query', array('query' => $sSql));
		}
		mysqli_free_result($result);
		return $res;
	}

	public static function QueryToArray($sSql)
	{
		$aData = array();
		$oKPI = new ExecutionKPI();
		$result = mysqli_query(self::$m_resDBLink, $sSql);
		$oKPI->ComputeStats('Query exec (mySQL)', $sSql);
		if (!$result)
		{
			throw new MySQLException('Failed to issue SQL query', array('query' => $sSql));
		}
		while ($aRow = mysqli_fetch_array($result, MYSQLI_BOTH))
		{
			$aData[] = $aRow;
		}
		mysqli_free_result($result);
		return $aData;
	}

	public static function QueryToCol($sSql, $col)
	{
		$aColumn = array();
		$aData = self::QueryToArray($sSql);
		foreach($aData as $aRow)
		{
			@$aColumn[] = $aRow[$col];
		}
		return $aColumn;
	}

	public static function ExplainQuery($sSql)
	{
		$aData = array();
		$result = mysqli_query(self::$m_resDBLink, "EXPLAIN $sSql");
		if (!$result)
		{
			throw new MySQLException('Failed to issue SQL query', array('query' => $sSql));
		}

		$aNames = self::GetColumns($result);

		$aData[] = $aNames;
		while ($aRow = mysqli_fetch_array($result, MYSQLI_ASSOC))
		{
			$aData[] = $aRow;
		}
		mysqli_free_result($result);
		return $aData;
	}

	public static function TestQuery($sSql)
	{
		$result = mysqli_query(self::$m_resDBLink, "EXPLAIN $sSql");
		if (!$result)
		{
			return self::GetError();
		}

		mysqli_free_result($result);
		return '';
	}

	public static function NbRows($result)
	{
		return mysqli_num_rows($result);
	}

	public static function AffectedRows()
	{
		return mysqli_affected_rows(self::$m_resDBLink);
	}

	public static function FetchArray($result)
	{
		return mysqli_fetch_array($result, MYSQLI_ASSOC);
	}

	public static function GetColumns($result)
	{
		$aNames = array();
		for ($i = 0; $i < (($___mysqli_tmp = mysqli_num_fields($result)) ? $___mysqli_tmp : 0) ; $i++)
		{
			$meta = mysqli_fetch_field_direct($result, $i);
			if (!$meta)
			{
				throw new MySQLException('mysql_fetch_field: No information available', array('query'=>$sSql, 'i'=>$i));
			}
			else
			{
				$aNames[] = $meta->name;
			}
		}
		return $aNames;
	}

	public static function Seek($result, $iRow)
	{
		return mysqli_data_seek($result, $iRow);
	}

	public static function FreeResult($result)
	{
		return ((mysqli_free_result($result) || (is_object($result) && (get_class($result) == "mysqli_result"))) ? true : false);
	}

	public static function IsTable($sTable)
	{
		$aTableInfo = self::GetTableInfo($sTable);
		return (!empty($aTableInfo));
	}

	public static function IsKey($sTable, $iKey)
	{
		$aTableInfo = self::GetTableInfo($sTable);
		if (empty($aTableInfo)) return false;
		if (!array_key_exists($iKey, $aTableInfo["Fields"])) return false;
		$aFieldData = $aTableInfo["Fields"][$iKey];
		if (!array_key_exists("Key", $aFieldData)) return false;
		return ($aFieldData["Key"] == "PRI"); 
	}

	public static function IsAutoIncrement($sTable, $sField)
	{
		$aTableInfo = self::GetTableInfo($sTable);
		if (empty($aTableInfo)) return false;
		if (!array_key_exists($sField, $aTableInfo["Fields"])) return false;
		$aFieldData = $aTableInfo["Fields"][$sField];
		if (!array_key_exists("Extra", $aFieldData)) return false;
		//MyHelpers::debug_breakpoint($aFieldData);
		return (strstr($aFieldData["Extra"], "auto_increment")); 
	}

	public static function IsField($sTable, $sField)
	{
		$aTableInfo = self::GetTableInfo($sTable);
		if (empty($aTableInfo)) return false;
		if (!array_key_exists($sField, $aTableInfo["Fields"])) return false;
		return true;
	}

	public static function IsNullAllowed($sTable, $sField)
	{
		$aTableInfo = self::GetTableInfo($sTable);
		if (empty($aTableInfo)) return false;
		if (!array_key_exists($sField, $aTableInfo["Fields"])) return false;
		$aFieldData = $aTableInfo["Fields"][$sField];
		return (strtolower($aFieldData["Null"]) == "yes");
	}

	public static function GetFieldType($sTable, $sField)
	{
		$aTableInfo = self::GetTableInfo($sTable);
		if (empty($aTableInfo)) return false;
		if (!array_key_exists($sField, $aTableInfo["Fields"])) return false;
		$aFieldData = $aTableInfo["Fields"][$sField];
		return ($aFieldData["Type"]);
	}

	public static function HasIndex($sTable, $sIndexId, $aFields = null)
	{
		$aTableInfo = self::GetTableInfo($sTable);
		if (empty($aTableInfo)) return false;
		if (!array_key_exists($sIndexId, $aTableInfo['Indexes'])) return false;

		if ($aFields == null)
		{
			// Just searching for the name
			return true;
		}

		// Compare the columns
		$sSearchedIndex = implode(',', $aFields);
		$sExistingIndex = implode(',', $aTableInfo['Indexes'][$sIndexId]);

		return ($sSearchedIndex == $sExistingIndex);
	}

	// Returns an array of (fieldname => array of field info)
	public static function GetTableFieldsList($sTable)
	{
		assert(!empty($sTable));
		
		$aTableInfo = self::GetTableInfo($sTable);
		if (empty($aTableInfo)) return array(); // #@# or an error ?

		return array_keys($aTableInfo["Fields"]);
	}
	
	// Cache the information about existing tables, and their fields
	private static $m_aTablesInfo = array();
	private static function _TablesInfoCacheReset()
	{
		self::$m_aTablesInfo = array();
	}
	private static function _TableInfoCacheInit($sTableName)
	{
		if (isset(self::$m_aTablesInfo[strtolower($sTableName)])
			&& (self::$m_aTablesInfo[strtolower($sTableName)] != null)) return;

		try
		{
			// Check if the table exists
			$aFields = self::QueryToArray("SHOW COLUMNS FROM `$sTableName`");
			// Note: without backticks, you get an error with some table names (e.g. "group")
			foreach ($aFields as $aFieldData)
			{
				$sFieldName = $aFieldData["Field"];
				self::$m_aTablesInfo[strtolower($sTableName)]["Fields"][$sFieldName] =
					array
					(
						"Name"=>$aFieldData["Field"],
						"Type"=>$aFieldData["Type"],
						"Null"=>$aFieldData["Null"],
						"Key"=>$aFieldData["Key"],
						"Default"=>$aFieldData["Default"],
						"Extra"=>$aFieldData["Extra"]
					);
			}
		}
		catch(MySQLException $e)
		{
			// Table does not exist
			self::$m_aTablesInfo[strtolower($sTableName)] = null;
		}

		if (!is_null(self::$m_aTablesInfo[strtolower($sTableName)]))
		{
			$aIndexes = self::QueryToArray("SHOW INDEXES FROM `$sTableName`");
			$aMyIndexes = array();
			foreach ($aIndexes as $aIndexColumn)
			{
				$aMyIndexes[$aIndexColumn['Key_name']][$aIndexColumn['Seq_in_index']-1] = $aIndexColumn['Column_name'];
			}
			self::$m_aTablesInfo[strtolower($sTableName)]["Indexes"] = $aMyIndexes;
		}
	}
	//public static function EnumTables()
	//{
	//	self::_TablesInfoCacheInit();
	//	return array_keys(self::$m_aTablesInfo);
	//}
	public static function GetTableInfo($sTable)
	{
		self::_TableInfoCacheInit($sTable);

		// perform a case insensitive match because on Windows the table names become lowercase :-(
		//foreach(self::$m_aTablesInfo as $sTableName => $aInfo)
		//{
		//	if (strtolower($sTableName) == strtolower($sTable))
		//	{
		//		return $aInfo;
		//	}
		//}
		return self::$m_aTablesInfo[strtolower($sTable)];
		//return null;
	}

	public static function DumpTable($sTable)
	{
		$sSql = "SELECT * FROM `$sTable`";
		$result = mysqli_query(self::$m_resDBLink, $sSql);
		if (!$result)
		{
			throw new MySQLException('Failed to issue SQL query', array('query' => $sSql));
		}

		$aRows = array();
		while ($aRow = mysqli_fetch_array($result, MYSQLI_ASSOC))
		{
			$aRows[] = $aRow;
		}
		mysqli_free_result($result);
		return $aRows;
	}
	
	/**
	 * Returns the value of the specified server variable
	 * @param string $sVarName Name of the server variable
	 * @return mixed Current value of the variable
	 */	   	
	public static function GetServerVariable($sVarName)
	{
		$result = '';
		$sSql = "SELECT @@$sVarName as theVar";
		$aRows = self::QueryToArray($sSql);
		if (count($aRows) > 0)
		{
			$result = $aRows[0]['theVar'];
		}
		return $result;
	}


	/**
	 * Returns the privileges of the current user
	 * @return string privileges in a raw format
	 */	   	
	public static function GetRawPrivileges()
	{
		try
		{
			$result = self::Query('SHOW GRANTS'); // [ FOR CURRENT_USER()]
		}
		catch(MySQLException $e)
		{
			return "Current user not allowed to see his own privileges (could not access to the database 'mysql' - $iCode)";
		}

		$aRes = array();
		while ($aRow = mysqli_fetch_array($result, MYSQLI_NUM))
		{
			// so far, only one column...
			$aRes[] = implode('/', $aRow);
		}
		mysqli_free_result($result);
		// so far, only one line...
		return implode(', ', $aRes);
	}

	/**
	 * Determine the slave status of the server
	 * @return bool true if the server is slave 
	 */	   	
	public static function IsSlaveServer()
	{
		try
		{
			$result = self::Query('SHOW SLAVE STATUS');
		}
		catch(MySQLException $e)
		{
			throw new CoreException("Current user not allowed to check the status", array('mysql_error' => $e->getMessage()));
		}

		if (mysqli_num_rows($result) == 0)
		{
			return false;
		}

		// Returns one single row anytime
		$aRow = mysqli_fetch_array($result, MYSQLI_ASSOC);
		mysqli_free_result($result);

		if (!isset($aRow['Slave_IO_Running']))
		{
			return false;
		}
		if (!isset($aRow['Slave_SQL_Running']))
		{
			return false;
		}

		// If at least one slave thread is running, then we consider that the slave is enabled
		if ($aRow['Slave_IO_Running'] == 'Yes')
		{
			return true;
		}
		if ($aRow['Slave_SQL_Running'] == 'Yes')
		{
			return true;
		}
		return false;
	}
}


?>
