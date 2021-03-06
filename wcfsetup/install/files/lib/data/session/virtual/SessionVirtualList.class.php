<?php
namespace wcf\data\session\virtual;
use wcf\data\acp\session\virtual\ACPSessionVirtualList;

/**
 * Virtual sessions for the frontend.
 * 
 * @see		\wcf\data\acp\session\virtual\ACPSessionVirtualList
 * @author	Tim Duesterhus
 * @copyright	2001-2018 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	WoltLabSuite\Core\Data\Session\Virtual
 *
 * @method	SessionVirtual		current()
 * @method	SessionVirtual[]	getObjects()
 * @method	SessionVirtual|null	search($objectID)
 * @property	SessionVirtual[]	$objects
 */
class SessionVirtualList extends ACPSessionVirtualList {
	/**
	 * @inheritDoc
	 */
	public $className = SessionVirtual::class;
}
