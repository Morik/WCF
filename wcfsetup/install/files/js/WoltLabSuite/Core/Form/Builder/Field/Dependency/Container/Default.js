/**
 * Default implementation for a container visibility handler due to the dependencies of its
 * children that only considers the visibility of all of its children.
 *
 * @author	Matthias Schmidt
 * @copyright	2001-2018 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @module	WoltLabSuite/Core/Form/Builder/Field/Dependency/Container/Default
 * @see 	module:WoltLabSuite/Core/Form/Builder/Field/Dependency/Abstract
 * @since	3.2
 */
define(['./Abstract', 'Core', '../Manager'], function(Abstract, Core, DependencyManager) {
	"use strict";
	
	/**
	 * @constructor
	 */
	function Default(containerId) {
		this.init(containerId);
	};
	Core.inherit(Default, Abstract, {
		/**
		 * @see	WoltLabSuite/Core/Form/Builder/Field/Dependency/Container/Default#checkContainer
		 */
		checkContainer: function() {
			var containerIsVisible = !elIsHidden(this._container);
			var containerShouldBeVisible = false;
			
			var children = this._container.children;
			for (var i = 0, length = children.length; i < length; i++) {
				if (!elIsHidden(children.item(i))) {
					containerShouldBeVisible = true;
				}
			}
			
			if (containerIsVisible !== containerShouldBeVisible) {
				if (containerShouldBeVisible) {
					elShow(this._container);
				}
				else {
					elHide(this._container);
				}
				
				// check containers again to make sure parent containers can react to
				// changing the visibility of this container
				DependencyManager.checkContainers();
			}
		}
	});
	
	return Default;
});
