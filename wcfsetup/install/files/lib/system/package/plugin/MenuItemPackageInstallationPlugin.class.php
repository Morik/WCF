<?php
namespace wcf\system\package\plugin;
use wcf\data\menu\item\MenuItem;
use wcf\data\menu\item\MenuItemEditor;
use wcf\data\menu\item\MenuItemList;
use wcf\data\menu\item\MenuItemNode;
use wcf\data\menu\Menu;
use wcf\data\menu\MenuList;
use wcf\data\page\PageNode;
use wcf\data\page\PageNodeTree;
use wcf\system\devtools\pip\IDevtoolsPipEntryList;
use wcf\system\devtools\pip\IGuiPackageInstallationPlugin;
use wcf\system\devtools\pip\TXmlGuiPackageInstallationPlugin;
use wcf\system\exception\SystemException;
use wcf\system\form\builder\container\FormContainer;
use wcf\system\form\builder\field\dependency\ValueFormFieldDependency;
use wcf\system\form\builder\field\IntegerFormField;
use wcf\system\form\builder\field\RadioButtonFormField;
use wcf\system\form\builder\field\SingleSelectionFormField;
use wcf\system\form\builder\field\TextFormField;
use wcf\system\form\builder\field\TitleFormField;
use wcf\system\form\builder\field\validation\FormFieldValidationError;
use wcf\system\form\builder\field\validation\FormFieldValidator;
use wcf\system\form\builder\IFormDocument;
use wcf\system\language\LanguageFactory;
use wcf\system\WCF;

/**
 * Installs, updates and deletes menu items.
 * 
 * @author	Alexander Ebert, Matthias Schmidt
 * @copyright	2001-2018 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	WoltLabSuite\Core\Acp\Package\Plugin
 * @since	3.0
 */
class MenuItemPackageInstallationPlugin extends AbstractXMLPackageInstallationPlugin implements IGuiPackageInstallationPlugin {
	use TXmlGuiPackageInstallationPlugin;
	
	/**
	 * @inheritDoc
	 */
	public $className = MenuItemEditor::class;
	
	/**
	 * @inheritDoc
	 */
	public $tagName = 'item';
	
	/**
	 * @inheritDoc
	 */
	protected function handleDelete(array $items) {
		$sql = "DELETE FROM	wcf".WCF_N."_menu_item
			WHERE		identifier = ?
					AND packageID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		
		WCF::getDB()->beginTransaction();
		foreach ($items as $item) {
			$statement->execute([
				$item['attributes']['identifier'],
				$this->installation->getPackageID()
			]);
		}
		WCF::getDB()->commitTransaction();
	}
	
	/**
	 * @inheritDoc
	 * @throws	SystemException
	 */
	protected function getElement(\DOMXPath $xpath, array &$elements, \DOMElement $element) {
		$nodeValue = $element->nodeValue;
		
		if ($element->tagName === 'title') {
			if (empty($element->getAttribute('language'))) {
				throw new SystemException("Missing required attribute 'language' for menu item '" . $element->parentNode->getAttribute('identifier') . "'");
			}
			
			// <title> can occur multiple times using the `language` attribute
			if (!isset($elements['title'])) $elements['title'] = [];
			
			$elements['title'][$element->getAttribute('language')] = $element->nodeValue;
		}
		else {
			$elements[$element->tagName] = $nodeValue;
		}
	}
	
	/**
	 * @inheritDoc
	 * @throws	SystemException
	 */
	protected function prepareImport(array $data) {
		$menuID = null;
		if (!empty($data['elements']['menu'])) {
			$menuID = $this->getMenuID($data['elements']['menu']);
			
			if ($menuID === null) {
				throw new SystemException("Unable to find menu '" . $data['elements']['menu'] . "' for menu item '" . $data['attributes']['identifier'] . "'");
			}
		}
		
		$parentItemID = null;
		if (!empty($data['elements']['parent'])) {
			if ($menuID !== null) {
				throw new SystemException("The menu item '" . $data['attributes']['identifier'] . "' can either have an associated menu or a parent menu item, but not both.");
			}
			
			$sql = "SELECT	*
				FROM	wcf".WCF_N."_menu_item
				WHERE	identifier = ?";
			$statement = WCF::getDB()->prepareStatement($sql, 1);
			$statement->execute([$data['elements']['parent']]);
			
			/** @var MenuItem|null $parent */
			$parent = $statement->fetchObject(MenuItem::class);
			if ($parent === null) {
				throw new SystemException("Unable to find parent menu item '" . $data['elements']['parent'] . "' for menu item '" . $data['attributes']['identifier'] . "'");
			}
			
			$parentItemID = $parent->itemID;
			$menuID = $parent->menuID;
		}
		
		if ($menuID === null && $parentItemID === null) {
			throw new SystemException("The menu item '" . $data['attributes']['identifier'] . "' must either have an associated menu or a parent menu item.");
		}
		
		$pageID = null;
		if (!empty($data['elements']['page'])) {
			$pageID = $this->getPageID($data['elements']['page']);
			
			if ($pageID === null) {
				throw new SystemException("Unable to find page '" . $data['elements']['page'] . "' for menu item '" . $data['attributes']['identifier'] . "'");
			}
		}
		
		$externalURL = (!empty($data['elements']['externalURL'])) ? $data['elements']['externalURL'] : '';
		
		if ($pageID === null && empty($externalURL)) {
			throw new SystemException("The menu item '" . $data['attributes']['identifier'] . "' must either have an associated page or an external url set.");
		}
		else if ($pageID !==  null && !empty($externalURL)) {
			throw new SystemException("The menu item '" . $data['attributes']['identifier'] . "' can either have an associated page or an external url, but not both.");
		}
		
		return [
			'externalURL' => $externalURL,
			'identifier' => $data['attributes']['identifier'],
			'menuID' => $menuID,
			'originIsSystem' => 1,
			'pageID' => $pageID,
			'parentItemID' => $parentItemID,
			'showOrder' => $this->getItemOrder($menuID, $parentItemID),
			'title' => $this->getI18nValues($data['elements']['title'])
		];
	}
	
	/**
	 * Returns the id of the menu with the given identifier. If no such menu
	 * exists, `null` is returned.
	 * 
	 * @param	string	$identifier
	 * @return	null|int
	 */
	protected function getMenuID($identifier) {
		$sql = "SELECT	menuID
			FROM	wcf" . WCF_N . "_menu
			WHERE	identifier = ?";
		$statement = WCF::getDB()->prepareStatement($sql, 1);
		$statement->execute([$identifier]);
		
		return $statement->fetchSingleColumn();
	}
	
	/**
	 * Returns the id of the page with the given identifier. If no such page
	 * exists, `null` is returned.
	 *
	 * @param	string	$identifier
	 * @return	null|int
	 */
	protected function getPageID($identifier) {
		$sql = "SELECT	pageID
			FROM	wcf" . WCF_N . "_page
			WHERE	identifier = ?";
		$statement = WCF::getDB()->prepareStatement($sql, 1);
		$statement->execute([$identifier]);
		
		return $statement->fetchSingleColumn();
	}
	
	/**
	 * @inheritDoc
	 */
	protected function findExistingItem(array $data) {
		$sql = "SELECT	*
			FROM	wcf".WCF_N."_menu_item
			WHERE	identifier = ?
				AND packageID = ?";
		$parameters = [
			$data['identifier'],
			$this->installation->getPackageID()
		];
		
		return [
			'sql' => $sql,
			'parameters' => $parameters
		];
	}
	
	/**
	 * @inheritDoc
	 */
	protected function import(array $row, array $data) {
		// updating menu items is not supported because all fields that could be modified
		// would potentially overwrite changes made by the user
		if (!empty($row)) {
			return new MenuItem(null, $row);
		}
		
		return parent::import($row, $data);
	}
	
	/**
	 * Returns the show order for a new item that will append it to the current
	 * menu or parent item.
	 * 
	 * @param	integer		$menuID
	 * @param	integer		$parentItemID
	 * @return	integer
	 */
	protected function getItemOrder($menuID, $parentItemID = null) {
		$sql = "SELECT	MAX(showOrder) AS showOrder
			FROM	wcf".WCF_N."_menu_item
			WHERE	" . ($parentItemID === null ? 'menuID' : 'parentItemID') . " = ?";
		$statement = WCF::getDB()->prepareStatement($sql, 1);
		$statement->execute([
			$parentItemID === null ? $menuID : $parentItemID
		]);
		
		$row = $statement->fetchSingleRow();
		
		return (!$row['showOrder']) ? 1 : $row['showOrder'] + 1;
	}
	
	/**
	 * @inheritDoc
	 * @since	3.1
	 */
	public static function getSyncDependencies() {
		return ['language', 'menu', 'page'];
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	public function addFormFields(IFormDocument $form) {
		$menuList = new MenuList();
		$menuList->readObjects();
		
		/** @var FormContainer $dataContainer */
		$dataContainer = $form->getNodeById('data');
		
		$dataContainer->appendChildren([
			TextFormField::create('identifier')
				->label('wcf.acp.pip.menuItem.identifier')
				->description('wcf.acp.pip.menuItem.identifier.description')
				->required()
				->addValidator(ObjectTypePackageInstallationPlugin::getObjectTypeAlikeValueValidator(
					'wcf.acp.pip.menuItem.identifier',
					4
				))
				->addValidator(new FormFieldValidator('uniqueness', function(TextFormField $formField) {
					if (
						$formField->getDocument()->getFormMode() === IFormDocument::FORM_MODE_CREATE ||
						$this->editedEntry->getAttribute('identifier') !== $formField->getValue()
					) {
						$menuItemList = new MenuItemList();
						$menuItemList->getConditionBuilder()->add('identifier = ?', [$formField->getValue()]);
						
						if ($menuItemList->countObjects() > 0) {
							$formField->addValidationError(
								new FormFieldValidationError(
									'notUnique',
									'wcf.acp.pip.menuItem.identifier.error.notUnique'
								)
							);
						}
					}
				})),
			
			TitleFormField::create()
				->required()
				->i18n()
				->i18nRequired()
				->languageItemPattern('__NONE__'),
			
			SingleSelectionFormField::create('menu')
				->label('wcf.acp.pip.menuItem.menu')
				->description('wcf.acp.pip.menuItem.menu.description')
				->required()
				->options(function() use ($menuList) {
					$options = [];
					foreach ($menuList as $menu) {
						$options[$menu->identifier] = $menu->identifier;
					}
					
					asort($options);
					
					return $options;
				}),
			
			RadioButtonFormField::create('linkType')
				->label('wcf.acp.pip.menuItem.linkType')
				->required()
				->options([
					'internal' => 'wcf.acp.pip.menuItem.linkType.internal',
					'external' => 'wcf.acp.pip.menuItem.linkType.external'
				])
				->value('internal'),
			
			SingleSelectionFormField::create('menuItemPage')
				->objectProperty('page')
				->label('wcf.acp.pip.menuItem.page')
				->description('wcf.acp.pip.menuItem.page.description')
				->required()
				->filterable()
				->options(function() {
					$pageNodeList = (new PageNodeTree())->getNodeList();
					
					$nestedOptions = [[
						'depth' => 0,
						'label' => 'wcf.global.noSelection',
						'value' => ''
					]];
					
					$packageIDs = array_merge(
						[$this->installation->getPackage()->packageID],
						array_keys($this->installation->getPackage()->getAllRequiredPackages())
					);
					
					/** @var PageNode $pageNode */
					foreach ($pageNodeList as $pageNode) {
						if (in_array($pageNode->packageID, $packageIDs)) {
							$nestedOptions[] = [
								'depth' => $pageNode->getDepth() - 1,
								'label' => $pageNode->name,
								'value' => $pageNode->identifier
							];
						}
					}
					
					return $nestedOptions;
				}, true),
			
			TextFormField::create('externalURL')
				->label('wcf.acp.pip.menuItem.externalURL')
				->description('wcf.acp.pip.menuItem.externalURL.description')
				->required()
				->i18n(),
			
			IntegerFormField::create('showOrder')
				->objectProperty('showorder')
				->label('wcf.acp.pip.menuItem.showOrder')
				->description('wcf.acp.pip.menuItem.showOrder.description')
				->objectProperty('showorder')
				->minimum(1)
		]);
		
		/** @var SingleSelectionFormField $menuField */
		$menuField = $form->getNodeById('menu');
		
		foreach ($menuList as $menu) {
			$dataContainer->insertBefore(
				SingleSelectionFormField::create('parentMenuItem' . $menu->menuID)
					->objectProperty('parent')
					->label('wcf.acp.pip.menuItem.parentMenuItem')
					->options(function() use($menu) {
						$options = [[
							'depth' => 0,
							'label' => 'wcf.global.noSelection',
							'value' => ''
						]];
						
						$packageIDs = array_merge(
							[$this->installation->getPackage()->packageID],
							array_keys($this->installation->getPackage()->getAllRequiredPackages())
						);
						
						/** @var MenuItemNode $menuItem */
						foreach ($menu->getMenuItemNodeList() as $menuItem) {
							if (in_array($menuItem->packageID, $packageIDs)) {
								$options[] = [
									'depth' => $menuItem->getDepth() - 1,
									'label' => $menuItem->identifier,
									'value' => $menuItem->identifier
								];
							}
						}
						
						if (count($options) === 1) {
							return [];
						}
						
						return $options;
					}, true)
					->addDependency(
						ValueFormFieldDependency::create('menu')
							->field($menuField)
							->values([$menu->identifier])
					),
				'linkType'
			);
		}
		
		// dependencies
		
		/** @var RadioButtonFormField $linkType */
		$linkType = $form->getNodeById('linkType');
		$form->getNodeById('menuItemPage')->addDependency(
			ValueFormFieldDependency::create('linkType')
				->field($linkType)
				->values(['internal'])
			);
		
		$form->getNodeById('externalURL')->addDependency(
			ValueFormFieldDependency::create('linkType')
				->field($linkType)
				->values(['external'])
		);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function getElementData(\DOMElement $element, $saveData = false) {
		$data = [
			'identifier' => $element->getAttribute('identifier'),
			'packageID' => $this->installation->getPackageID(),
			'originIsSystem' => 1,
			'title' => []
		];
		
		/** @var \DOMElement $title */
		foreach ($element->getElementsByTagName('title') as $title) {
			$data['title'][LanguageFactory::getInstance()->getLanguageByCode($title->getAttribute('language'))->languageID] = $title->nodeValue;
		}
		
		foreach (['externalURL', 'menu', 'page', 'parent', 'showOrder'] as $optionalElementName) {
			$optionalElement = $element->getElementsByTagName($optionalElementName)->item(0);
			if ($optionalElement !== null) {
				$data[$optionalElementName] = $optionalElement->nodeValue;
			}
		}
		
		if (isset($data['parent'])) {
			$menuItemList = new MenuItemList();
			$menuItemList->getConditionBuilder()->add('identifier = ?', [$data['parent']]);
			$menuItemList->getConditionBuilder()->add('packageID IN (?)', [
				array_merge(
					[$this->installation->getPackage()->packageID],
					array_keys($this->installation->getPackage()->getAllRequiredPackages())
				)
			]);
			$menuItemList->readObjects();
			
			if (count($menuItemList) === 1) {
				if ($saveData) {
					$data['menuID'] = $menuItemList->current()->menuID;
				}
				else {
					$data['menu'] = (new Menu($menuItemList->current()->menuID))->identifier;
				}
			}
		}
		else if (isset($data['menu']) && $saveData) {
			$data['menuID'] = $this->getMenuID($data['menu']);
			unset($data['menu']);
		}
		
		if ($saveData) {
			// updating menu items is not supported thus handling the title
			// array causes issues
			if ($this->editedEntry !== null) {
				unset($data['title']);
			}
			else {
				$titles = [];
				foreach ($data['title'] as $languageID => $title) {
					$titles[LanguageFactory::getInstance()->getLanguage($languageID)->languageCode] = $title;
				}
				
				$data['title'] = $titles;
			}
			
			if (isset($data['page'])) {
				$data['pageID'] = $this->getPageID($data['page']);
				unset($data['page']);
			}
		}
		
		return $data;
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	public function getElementIdentifier(\DOMElement $element) {
		return $element->getAttribute('identifier');
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function setEntryListKeys(IDevtoolsPipEntryList $entryList) {
		$entryList->setKeys([
			'identifier' => 'wcf.acp.pip.menuItem.identifier',
			'menu' => 'wcf.acp.pip.menuItem.menu'
		]);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function sortDocument(\DOMDocument $document) {
		$this->sortImportDelete($document);
		
		$this->sortChildNodes($document->getElementsByTagName('import'), function(\DOMElement $element1, \DOMElement $element2) {
			// first, compare by `menu`, then by `parent` ...
			foreach (['menu', 'parent'] as $element) {
				$compareElement1 = $element1->getElementsByTagName($element)->item(0);
				$compareElement2 = $element2->getElementsByTagName($element)->item(0);
				
				if ($compareElement1 !== null) {
					if ($compareElement2 !== null) {
						$compare = $compareElement1->nodeValue <=> $compareElement2->nodeValue;
						
						if ($compare !== 0) {
							return $compare;
						}
					}
					
					return -1;
				}
				else if ($compareElement2 !== null) {
					return 1;
				}
			}
			
			// ... and lastly by `identifier`
			return strcmp(
				$element1->getAttribute('identifier'),
				$element2->getAttribute('identifier')
			);
		});
		
		$this->sortChildNodes($document->getElementsByTagName('delete'), function(\DOMElement $element1, \DOMElement $element2) {
			return strcmp(
				$element1->getAttribute('identifier'),
				$element2->getAttribute('identifier')
			);
		});
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function writeEntry(\DOMDocument $document, IFormDocument $form) {
		$formData = $form->getData();
		$data = $formData['data'];
		
		$menuItem = $document->createElement('item');
		$menuItem->setAttribute('identifier', $data['identifier']);
		
		if (!empty($data['menu'])) {
			$menuItem->appendChild($document->createElement('menu', $data['menu']));
		}
		else if (!empty($data['parent'])) {
			$menuItem->appendChild($document->createElement('parent', $data['parent']));
		}
		
		foreach ($formData['title_i18n'] as $languageID => $title) {
			$title = $document->createElement('title', $this->getAutoCdataValue($title));
			$title->setAttribute('language', LanguageFactory::getInstance()->getLanguage($languageID)->languageCode);
			
			$menuItem->appendChild($title);
		}
		
		foreach (['page', 'externalURL', 'showOrder'] as $property) {
			if (!empty($data[$property])) {
				$menuItem->appendChild($document->createElement($property, (string)$data[$property]));
			}
		}
		
		$document->getElementsByTagName('import')->item(0)->appendChild($menuItem);
		
		return $menuItem;
	}
}
