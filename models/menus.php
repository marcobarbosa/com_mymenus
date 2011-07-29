<?php
/**
 * @version		$Id$
 * @copyright	Copyright (C) 2005 - 2011 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.application.component.modellist');

/**
 * Menu List Model for Menus.
 *
 * @package		Joomla.Administrator
 * @subpackage	com_menus
 * @since		1.6
 */
class MenusModelMenus extends JModelList
{
	/**
	 * Constructor.
	 *
	 * @param	array	An optional associative array of configuration settings.
	 * @see		JController
	 * @since	1.6
	 */
	public function __construct($config = array())
	{
		if (empty($config['filter_fields'])) {
			$config['filter_fields'] = array(
				'id', 'a.id',
				'title', 'a.title',
				'menutype', 'a.menutype',
			);
		}

		parent::__construct($config);
	}
	
	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @since	1.6
	 */
	protected function populateState($ordering = null, $direction = null)
	{
		// Initialise variables.
		$app = JFactory::getApplication('administrator');

		// Load the filter state.
		$clientId = $this->getUserStateFromRequest($this->context.'.filter.client_id', 'filter_client_id', 0, 'int', false);
		$previousId = $app->getUserState($this->context.'.filter.client_id_previous', null);
		if($previousId != $clientId || $previousId === null){
			$this->getUserStateFromRequest($this->context.'.filter.client_id_previous', 'filter_client_id_previous', 0, 'int', true);
			$app->setUserState($this->context.'.filter.client_id_previous', $clientId);
		}
		$this->setState('filter.client_id', $clientId);

		// Load the parameters.
		$params = JComponentHelper::getParams('com_menus');
		$this->setState('params', $params);

		// List state information.
		parent::populateState('a.id', 'asc');
	}
	
	/**
	 * Method to get a store id based on model configuration state.
	 *
	 * This is necessary because the model is used by the component and
	 * different modules that might need different sets of data or different
	 * ordering requirements.
	 *
	 * @param	string	A prefix for the store id.
	 *
	 * @return	string	A store id.
	 */
	protected function getStoreId($id = '')
	{
		// Compile the store id.
		$id	.= ':'.$this->getState('filter.client_id');

		return parent::getStoreId($id);
	}

	/**
	 * Overrides the getItems method to attach additional metrics to the list.
	 *
	 * @return	mixed	An array of data items on success, false on failure.
	 * @since	1.6.1
	 */
	public function getItems()
	{
		// Get a storage key.
		$store = $this->getStoreId('getItems');

		// Try to load the data from internal storage.
		if (!empty($this->cache[$store])) {
			return $this->cache[$store];
		}

		// Load the list items.
		$items = parent::getItems();

		// If emtpy or an error, just return.
		if (empty($items)) {
			return array();
		}

		// Getting the following metric by joins is WAY TOO SLOW.
		// Faster to do three queries for very large menu trees.

		// Get the menu types of menus in the list.
		$db = $this->getDbo();
		$menuTypes = JArrayHelper::getColumn($items, 'menutype');

		// Quote the strings.
		$menuTypes = implode(
			',',
			array_map(array($db, 'quote'), $menuTypes)
		);

		// Get the published menu counts.
		$query = $db->getQuery(true)
			->select('m.menutype, COUNT(DISTINCT m.id) AS count_published')
			->from('#__menu AS m')
			->where('m.published = 1');
			if (!empty($menuTypes)) {
				$query->where('m.menutype IN ('.$menuTypes.')');
			}
			$query->group('m.menutype')
			;
		$db->setQuery($query);
		$countPublished = $db->loadAssocList('menutype', 'count_published');

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		// Get the unpublished menu counts.
		$query->clear('where')
		->where('m.published = 0');
		if (!empty($menuTypes)) {
				$query->where('m.menutype IN ('.$menuTypes.')');
		}
		$db->setQuery($query);
		$countUnpublished = $db->loadAssocList('menutype', 'count_published');

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		// Get the trashed menu counts.
		$query->clear('where')
			->where('m.published = -2');
		if (!empty($menuTypes)) {
				$query->where('m.menutype IN ('.$menuTypes.')');
		}
		$db->setQuery($query);
		$countTrashed = $db->loadAssocList('menutype', 'count_published');

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		// Inject the values back into the array.
		foreach ($items as $item)
		{
			$item->count_published		= isset($countPublished[$item->menutype]) ? $countPublished[$item->menutype] : 0;
			$item->count_unpublished	= isset($countUnpublished[$item->menutype]) ? $countUnpublished[$item->menutype] : 0;
			$item->count_trashed		= isset($countTrashed[$item->menutype]) ? $countTrashed[$item->menutype] : 0;
		}

		// Add the items to the internal cache.
		$this->cache[$store] = $items;

		return $this->cache[$store];
	}

	/**
	 * Method to build an SQL query to load the list data.
	 *
	 * @return	string	An SQL query
	 */
	protected function getListQuery()
	{
		// Create a new query object.
		$db = $this->getDbo();
		$query = $db->getQuery(true);

		// Select all fields from the table.
		$query->select('DISTINCT b.menutype AS nepravimenutype' .',' .$this->getState('list.select', 'a.*'));
		$query->from('`#__menu` AS b');
		$query->innerJoin('`#__menu_types` AS a ON b.menutype = a.menutype');
		// copied from ModMenuHelper->getMenus
		$query->where('(b.client_id = '.(int)$db->getEscaped($this->getState('filter.client_id', 0)). ' OR b.client_id IS NULL)');
		$query->group('a.id');

		// Add the list ordering clause.
		$query->order($db->getEscaped($this->getState('list.ordering', 'a.id')).' '.$db->getEscaped($this->getState('list.direction', 'ASC')));

		//echo nl2br(str_replace('#__','jos_',(string)$query)).'<hr/>';
		
		

		
		return $query;
	}


	/**
	 * Gets a list of all mod_mainmenu modules and collates them by menutype
	 *
	 * @return	array
	 */
	function &getModules()
	{
		$model	= JModel::getInstance('Menu', 'MenusModel', array('ignore_request' => true));
		$result	= &$model->getModules();

		return $result;
	}
}
