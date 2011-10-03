<?php
/**
 *  @package AkeebaSubs
 *  @subpackage FrameworkOnFramework
 *  @copyright Copyright (c)2010-2011 Nicholas K. Dionysopoulos
 *  @license GNU General Public License version 3, or later
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

jimport('joomla.database.table');

require_once(dirname(__FILE__).'/input.php');

/**
 * FrameworkOnFramework table class
 * 
 * FrameworkOnFramework is a set of classes whcih extend Joomla! 1.5 and later's
 * MVC framework with features making maintaining complex software much easier,
 * without tedious repetitive copying of the same code over and over again.
 */
class FOFTable extends JTable
{
	
	public function &getInstance($type = null, $prefix = 'JTable', $config = array())
	{
		static $instances = array();
		
		if(!array_key_exists('option', $config)) $config['option'] = JRequest::getCmd('option','com_foobar');
		if(!array_key_exists('view', $config)) $config['view'] = JRequest::getCmd('view','cpanel');
		if(is_null($type)) {
			if($prefix == 'JTable') $prefix = 'Table';
			$type = $config['view'];
		}
		
		$type = preg_replace('/[^A-Z0-9_\.-]/i', '', $type);
		$tableClass = $prefix.ucfirst($type);
		
		if(!array_key_exists($tableClass, $instances)) {
			if (!class_exists( $tableClass )) {
				$app = JFactory::getApplication();
				if($app->isSite()) {
					$basePath = JPATH_SITE;
				} else {
					$basePath = JPATH_ADMINISTRATOR;
				}
				
				$searchPaths = array(
					$basePath.'/components/'.$config['option'].'/tables',
					JPATH_ADMINISTRATOR.'/components/'.$config['option'].'/tables'
				);
				if(array_key_exists('tablepath', $config)) {
					array_unshift($searchPaths, $config['tablepath']);
				}
				
				jimport('joomla.filesystem.path');
				$path = JPath::find(
					$searchPaths,
					strtolower($type).'.php'
				);
				
				if ($path) {
					require_once $path;
				}
			}
			
			if (!class_exists( $tableClass )) {
				$tableClass = 'FOFTable';
			}
		}
		
		$tbl_common = str_replace('com_', '', $config['option']).'_'
			. strtolower($type);
		if(!array_key_exists('tbl', $config)) {
			$config['tbl'] = '#__'.$tbl_common;
		}
		if(!array_key_exists('tbl_key', $config)) {
			$config['tbl_key'] = $tbl_common.'_id';
		}
		if(!array_key_exists('db', $config)) {
			$config['db'] = JFactory::getDBO();
		}

		$instance = new $tableClass($config['tbl'],$config['tbl_key'],$config['db']);
			
		$instances[$tableClass] = $instance;
		
		return $instances[$tableClass];
	}
	
	function __construct( $table, $key, &$db )
	{
		parent::__construct($table, $key, $db);
		
		// Auto fetch the whole lot of field defs
		if(!version_compare('JVERSION', '1.6.0', 'ge')) {
			// Initialise the table properties.
			if ($fields = $this->j15getFields()) {
				foreach ($fields as $name => $v)
				{
					// Add the field if it is not already present.
					if (!property_exists($this, $name)) {
						$this->$name = null;
					}
				}
			}
		}
	}
	
	/**
	 * Generic check for whether dependancies exist for this object in the db schema
	 */
	public function canDelete( $oid=null, $joins=null )
	{
		$k = $this->_tbl_key;
		if ($oid) {
			$this->$k = intval( $oid );
		}

		if (is_array( $joins ))
		{
			$select = "`master`.$k";
			$join = "";
			foreach( $joins as $table )
			{
				$select .= ', COUNT(DISTINCT `'.$table['name'].'`.'.$table['idfield'].') AS '.$table['idalias'];
				$join .= ' LEFT JOIN '.$table['name'].' ON '.$table['joinfield'].' = `master`.'.$k;
			}

			$query = 'SELECT '. $select
			. ' FROM '. $this->_tbl.' AS `master` '
			. $join
			. ' WHERE `master`.'. $k .' = '. $this->_db->Quote($this->$k)
			. ' GROUP BY `master`.'. $k
			;
			$this->_db->setQuery( $query );

			if (!$obj = $this->_db->loadObject())
			{
				$this->setError($this->_db->getErrorMsg());
				return false;
			}
			$msg = array();
			$i = 0;
			foreach( $joins as $table )
			{
				$k = $table['idfield'] . $i;
				if ($obj->$k)
				{
					$msg[] = JText::_( $table['label'] );
				}
				$i++;
			}

			if (count( $msg ))
			{
				$this->setError("noDeleteRecord" . ": " . implode( ', ', $msg ));
				return false;
			}
			else
			{
				return true;
			}
		}

		return true;
	}
	
	public function bind( $from, $ignore=array() )
	{
		if(!$this->onBeforeBind($from)) return false;
		return parent::bind($from, $ignore);
	}
	
	public function load( $oid=null )
	{
		$result = parent::load($oid);
		$this->onAfterLoad($result);
		return $result;
	}
	
	public function store( $updateNulls=false )
	{
		if(!$this->onBeforeStore($updateNulls)) return false;
		$result = parent::store($updateNulls);
		if($result) {
			$result = $this->onAfterStore();
		}
		return $result;
	}
	
	public function move( $dirn, $where='' )
	{
		if(!$this->onBeforeMove($dirn, $where)) return false;
		$result = parent::move($dirn, $where);
		if($result) {
			$result = $this->onAfterMove();
		}
		return $result;
	}
	
	public function reorder( $where='' )
	{
		if(!$this->onBeforeReorder($where)) return false;
		$result = parent::reorder($where);
		if($result) {
			$result = $this->onAfterReorder();
		}
		return $result;
	}
	
	public function checkout( $who, $oid = null )
	{
		if (!(
			in_array( 'locked_by', array_keys($this->getProperties()) ) ||
	 		in_array( 'locked_on', array_keys($this->getProperties()) )
		)) {
			return true;
		}

		$k = $this->_tbl_key;
		if ($oid !== null) {
			$this->$k = $oid;
		}

		$date =& JFactory::getDate();
		$time = $date->toMysql();

		$query = 'UPDATE '.$this->_db->nameQuote( $this->_tbl ) .
			' SET locked_by = '.(int)$who.', locked_on = '.$this->_db->Quote($time) .
			' WHERE '.$this->_tbl_key.' = '. $this->_db->Quote($this->$k);
		$this->_db->setQuery( $query );

		$this->checked_out = $who;
		$this->checked_out_time = $time;

		return $this->_db->query();
	}
	
	function checkin( $oid=null )
	{
		if (!(
			in_array( 'locked_by', array_keys($this->getProperties()) ) ||
	 		in_array( 'locked_on', array_keys($this->getProperties()) )
		)) {
			return true;
		}

		$k = $this->_tbl_key;

		if ($oid !== null) {
			$this->$k = $oid;
		}

		if ($this->$k == NULL) {
			return false;
		}

		$query = 'UPDATE '.$this->_db->nameQuote( $this->_tbl ).
				' SET locked_by = 0, locked_on = '.$this->_db->Quote($this->_db->getNullDate()) .
				' WHERE '.$this->_tbl_key.' = '. $this->_db->Quote($this->$k);
		$this->_db->setQuery( $query );

		$this->checked_out = 0;
		$this->checked_out_time = '';

		return $this->_db->query();
	}
	
	function isCheckedOut( $with = 0, $against = null)
	{
		if(isset($this) && is_a($this, 'JTable') && is_null($against)) {
			$against = $this->get( 'locked_by' );
		}

		//item is not checked out, or being checked out by the same user
		if (!$against || $against == $with) {
			return  false;
		}

		$session =& JTable::getInstance('session');
		return $session->exists($against);
	}
	
	function publish( $cid=null, $publish=1, $user_id=0 )
	{
		JArrayHelper::toInteger( $cid );
		$user_id	= (int) $user_id;
		$publish	= (int) $publish;
		$k			= $this->_tbl_key;

		if (count( $cid ) < 1)
		{
			if ($this->$k) {
				$cid = array( $this->$k );
			} else {
				$this->setError("No items selected.");
				return false;
			}
		}
		
		if(!$this->onBeforePublish($cid, $publish)) return false;

		$cids = $k . '=' . implode( ' OR ' . $k . '=', $cid );

		$query = 'UPDATE '. $this->_tbl
		. ' SET enabled = ' . (int) $publish
		. ' WHERE ('.$cids.')'
		;

		$checkin = in_array( 'locked_by', array_keys($this->getProperties()) );
		if ($checkin)
		{
			$query .= ' AND (locked_by = 0 OR locked_by = '.(int) $user_id.')';
		}

		$this->_db->setQuery( $query );
		if (!$this->_db->query())
		{
			$this->setError($this->_db->getErrorMsg());
			return false;
		}

		if (count( $cid ) == 1 && $checkin)
		{
			if ($this->_db->getAffectedRows() == 1) {
				$this->checkin( $cid[0] );
				if ($this->$k == $cid[0]) {
					$this->published = $publish;
				}
			}
		}
		$this->setError('');
		return true;
	}
	
	public function delete( $oid=null )
	{
		if(!$this->onBeforeDelete($oid)) return false;
		$result = parent::delete($oid);
		if($result) {
			$result = $this->onAfterDelete();
		}
		return $result;
	}
	
	public function hit( $oid=null, $log=false )
	{
		if(!$this->onBeforeHit($oid, $log)) return false;
		$result = parent::hit($oid, $log);
		if($result) {
			$result = $this->onAfterHit();
		}
		return $result;
	}
	
	/**
	 * Export item list to CSV
	 */
	function toCSV($separator=',')
	{
		$csv = array();

		foreach (get_object_vars( $this ) as $k => $v)
		{
			if (is_array($v) or is_object($v) or $v === NULL)
			{
				continue;
			}
			if ($k[0] == '_')
			{ // internal field
				continue;
			}
			$csv[] = '"'.str_replace('"', '\"', $v).'"';
		}
		$csv = implode($separator, $csv);

		return $csv;
	}
	
	/**
	 * Get the header for exporting item list to CSV
	 */
	function getCSVHeader($separator=',')
	{
		$csv = array();

		foreach (get_object_vars( $this ) as $k => $v)
		{
			if (is_array($v) or is_object($v) or $v === NULL)
			{
				continue;
			}
			if ($k[0] == '_')
			{ // internal field
				continue;
			}
			$csv[] = '"'.str_replace('"', '\"', $k).'"';
		}
		$csv = implode($separator, $csv);

		return $csv;
	}
	
	/**
	 * Get the columns from database table.
	 *
	 * @return  mixed  An array of the field names, or false if an error occurs.
	 */
	public function j15getFields()
	{
		static $cache = null;

		if ($cache === null) {
			// Lookup the fields for this table only once.
			$name	= $this->_tbl;
			$fields	= $this->_db->getTableFields($name, false);

			if (!isset($fields[$name])) {
				return false;
			}
			$cache = $fields[$name];
		}

		return $cache;
	}
	
	protected function onBeforeBind(&$from)
	{
		return true;
	}
	
	protected function onAfterLoad(&$result)
	{
	}
	
	protected function onBeforeStore($updateNulls)
	{
		return true;
	}
	
	protected function onAfterStore()
	{
		return true;
	}
	
	protected function onBeforeMove($updateNulls)
	{
		return true;
	}
	
	protected function onAfterMove()
	{
		return true;
	}
	
	protected function onBeforeReorder($where = '')
	{
		return true;
	}
	
	protected function onAfterReorder()
	{
		return true;
	}
	
	protected function onBeforeDelete($oid)
	{
		return true;
	}
	
	protected function onAfterDelete()
	{
		return true;
	}
	
	protected function onBeforeHit($oid, $log)
	{
		return true;
	}
	
	protected function onAfterHit()
	{
		return true;
	}
	
	protected function onBeforePublish(&$cid, $publish)
	{
		return true;
	}
}