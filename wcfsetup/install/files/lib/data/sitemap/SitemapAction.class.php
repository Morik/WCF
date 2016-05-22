<?php
namespace wcf\data\sitemap;
use wcf\data\AbstractDatabaseObjectAction;
use wcf\system\sitemap\SitemapHandler;
use wcf\system\WCF;

/**
 * Executes sitemap-related actions.
 * 
 * @author	Alexander Ebert
 * @copyright	2001-2016 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf
 * @subpackage	data.sitemap
 * @category	Community Framework
 * 
 * @method	Sitemap			create()
 * @method	SitemapEditor[]		getObjects()
 * @method	SitemapEditor		getSingleObject()
 */
class SitemapAction extends AbstractDatabaseObjectAction {
	/**
	 * @inheritDoc
	 */
	protected $allowGuestAccess = ['getSitemap'];
	
	/**
	 * Validates the 'getSitemap' action.
	 */
	public function validateGetSitemap() {
		if (isset($this->parameters['sitemapName'])) {
			SitemapHandler::getInstance()->validateSitemapName($this->parameters['sitemapName']);
		}
	}
	
	/**
	 * Returns sitemap for active application group.
	 * 
	 * @return	array
	 */
	public function getSitemap() {
		if (isset($this->parameters['sitemapName'])) {
			return [
				'sitemapName' => $this->parameters['sitemapName'],
				'template' => SitemapHandler::getInstance()->getSitemap($this->parameters['sitemapName'])
			];
		}
		else {
			$sitemapName = SitemapHandler::getInstance()->getDefaultSitemapName();
			
			WCF::getTPL()->assign([
				'defaultSitemapName' => $sitemapName,
				'sitemap' => SitemapHandler::getInstance()->getSitemap($sitemapName),
				'tree' => SitemapHandler::getInstance()->getTree()
			]);
			
			return [
				'sitemapName' => $sitemapName,
				'template' => WCF::getTPL()->fetch('sitemap')
			];
		}
	}
}
