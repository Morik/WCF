<?php
namespace wcf\system\package\plugin;
use wcf\data\box\Box;
use wcf\data\box\BoxEditor;
use wcf\data\box\BoxList;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\page\PageNode;
use wcf\data\page\PageNodeTree;
use wcf\system\box\AbstractDatabaseObjectListBoxController;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\devtools\pip\IDevtoolsPipEntryList;
use wcf\system\devtools\pip\IGuiPackageInstallationPlugin;
use wcf\system\devtools\pip\TXmlGuiPackageInstallationPlugin;
use wcf\system\exception\SystemException;
use wcf\system\form\builder\container\FormContainer;
use wcf\system\form\builder\container\TabFormContainer;
use wcf\system\form\builder\container\TabMenuFormContainer;
use wcf\system\form\builder\field\BooleanFormField;
use wcf\system\form\builder\field\dependency\ValueFormFieldDependency;
use wcf\system\form\builder\field\ItemListFormField;
use wcf\system\form\builder\field\MultilineTextFormField;
use wcf\system\form\builder\field\MultipleSelectionFormField;
use wcf\system\form\builder\field\RadioButtonFormField;
use wcf\system\form\builder\field\SingleSelectionFormField;
use wcf\system\form\builder\field\TextFormField;
use wcf\system\form\builder\field\TitleFormField;
use wcf\system\form\builder\field\validation\FormFieldValidationError;
use wcf\system\form\builder\field\validation\FormFieldValidator;
use wcf\system\form\builder\IFormDocument;
use wcf\system\language\LanguageFactory;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Installs, updates and deletes boxes.
 * 
 * TODO: Finalize GUI implementation
 * 
 * @author	Alexander Ebert, Matthias Schmidt
 * @copyright	2001-2018 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	WoltLabSuite\Core\Acp\Package\Plugin
 * @since	3.0
 */
class BoxPackageInstallationPlugin extends AbstractXMLPackageInstallationPlugin implements IGuiPackageInstallationPlugin {
	use TXmlGuiPackageInstallationPlugin;
	
	/**
	 * @inheritDoc
	 */
	public $className = BoxEditor::class;
	
	/**
	 * list of created or updated boxes by id
	 * @var BoxEditor[]
	 */
	protected $boxes = [];
	
	/**
	 * box contents
	 * @var	array
	 */
	protected $content = [];
	
	/**
	 * list of element names which are not considered as additional data
	 * @var	string[]
	 */
	public static $reservedTags = ['boxType', 'content', 'cssClassName', 'name', 'objectType', 'position', 'showHeader', 'visibilityExceptions', 'visibleEverywhere'];
	
	/**
	 * @inheritDoc
	 */
	public $tagName = 'box';
	
	/**
	 * visibility exceptions per box
	 * @var	string[]
	 */
	public $visibilityExceptions = [];
	
	/**
	 * @inheritDoc
	 */
	protected function handleDelete(array $items) {
		$sql = "DELETE FROM	wcf".WCF_N."_box
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
		
		if ($element->tagName === 'name') {
			if (empty($element->getAttribute('language'))) {
				throw new SystemException("Missing required attribute 'language' for '" . $element->tagName . "' element (box '" . $element->parentNode->getAttribute('identifier') . "')");
			}
			
			// element can occur multiple times using the `language` attribute
			if (!isset($elements[$element->tagName])) $elements[$element->tagName] = [];
			
			$elements[$element->tagName][$element->getAttribute('language')] = $element->nodeValue;
		}
		else if ($element->tagName === 'content') {
			// content can occur multiple times using the `language` attribute
			if (!isset($elements['content'])) $elements['content'] = [];
			
			$children = [];
			/** @var \DOMElement $child */
			foreach ($xpath->query('child::*', $element) as $child) {
				$children[$child->tagName] = $child->nodeValue;
			}
			
			if (empty($children['title'])) {
				throw new SystemException("Expected non-empty child element 'title' for 'content' element (box '" . $element->parentNode->getAttribute('identifier') . "')");
			}
			
			$elements['content'][$element->getAttribute('language')] = [
				'content' => isset($children['content']) ? $children['content'] : '',
				'title' => $children['title']
			];
		}
		else if ($element->tagName === 'visibilityExceptions') {
			$elements['visibilityExceptions'] = [];
			/** @var \DOMElement $child */
			foreach ($xpath->query('child::*', $element) as $child) {
				$elements['visibilityExceptions'][] = $child->nodeValue;
			}
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
		$content = [];
		$boxType = $data['elements']['boxType'];
		$objectTypeID = null;
		$identifier = $data['attributes']['identifier'];
		$isMultilingual = false;
		$position = $data['elements']['position'];
		
		if (!in_array($position, ['bottom', 'contentBottom', 'contentTop', 'footer', 'footerBoxes', 'headerBoxes', 'hero', 'sidebarLeft', 'sidebarRight', 'top'])) {
			throw new SystemException("Unknown box position '{$position}' for box '{$identifier}'");
		}
		
		// pick the display name by choosing the default language, or 'en' or '' (empty string)
		$defaultLanguageCode = LanguageFactory::getInstance()->getDefaultLanguage()->getFixedLanguageCode();
		if (isset($data['elements']['name'][$defaultLanguageCode])) {
			// use the default language
			$name = $data['elements']['name'][$defaultLanguageCode];
		}
		else if (isset($data['elements']['name']['en'])) {
			// use the value for English
			$name = $data['elements']['name']['en'];
		}
		else {
			// fallback to the display name without/empty language attribute
			$name = $data['elements']['name'][''];
		}
		
		switch ($boxType) {
			/** @noinspection PhpMissingBreakStatementInspection */
			case 'system':
				if (empty($data['elements']['objectType'])) {
					throw new SystemException("Missing required element 'objectType' for 'system'-type box '{$identifier}'");
				}
				
				$sql = "SELECT		objectTypeID
					FROM		wcf".WCF_N."_object_type object_type
					LEFT JOIN	wcf".WCF_N."_object_type_definition object_type_definition
					ON		(object_type_definition.definitionID = object_type.definitionID)
					WHERE		objectType = ?
							AND definitionName = ?";
				$statement = WCF::getDB()->prepareStatement($sql);
				$statement->execute([$data['elements']['objectType'], 'com.woltlab.wcf.boxController']);
				$objectTypeID = $statement->fetchSingleColumn();
				if (!$objectTypeID) {
					throw new SystemException("Unknown object type '{$data['elements']['objectType']}' for 'system'-type box '{$identifier}'");
				}
				
				$isMultilingual = true;
				
				// fallthrough
			
			case 'html':
			case 'text':
			case 'tpl':
				if (empty($data['elements']['content'])) {
					if ($boxType === 'system') {
						break;
					}
					
					throw new SystemException("Missing required 'content' element(s) for box '{$identifier}'");
				}
				
				if (count($data['elements']['content']) === 1) {
					if (!isset($data['elements']['content'][''])) {
						throw new SystemException("Expected one 'content' element without a 'language' attribute for box '{$identifier}'");
					}
				}
				else {
					$isMultilingual = true;
					
					if (isset($data['elements']['content'][''])) {
						throw new SystemException("Cannot mix 'content' elements with and without 'language' attribute for box '{$identifier}'");
					}
				}
				
				$content = $data['elements']['content'];
				
				break;
			
			default:
				throw new SystemException("Unknown type '{$boxType}' for box '{$identifier}");
				break;
		}
		
		if (!empty($data['elements']['visibilityExceptions'])) {
			$this->visibilityExceptions[$identifier] = $data['elements']['visibilityExceptions'];
		}
		
		$additionalData = [];
		foreach ($data['elements'] as $tagName => $nodeValue) {
			if (!in_array($tagName, self::$reservedTags)) {
				$additionalData[$tagName] = $nodeValue;
			}
		}
		
		return [
			'identifier' => $identifier,
			'content' => $content,
			'name' => $name,
			'boxType' => $boxType,
			'position' => $position,
			'showOrder' => $this->getItemOrder($position),
			'visibleEverywhere' => (!empty($data['elements']['visibleEverywhere'])) ? 1 : 0,
			'isMultilingual' => $isMultilingual ? '1' : '0',
			'cssClassName' => (!empty($data['elements']['cssClassName'])) ? $data['elements']['cssClassName'] : '',
			'showHeader' => (!empty($data['elements']['showHeader'])) ? 1 : 0,
			'originIsSystem' => 1,
			'objectTypeID' => $objectTypeID,
			'additionalData' => serialize($additionalData)
		];
	}
	
	/**
	 * @inheritDoc
	 */
	protected function findExistingItem(array $data) {
		$sql = "SELECT	*
			FROM	wcf".WCF_N."_box
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
	 * Returns the show order for a new item that will append it to the current
	 * menu or parent item.
	 *
	 * @param	string		$position	box position
	 * @return	integer
	 */
	protected function getItemOrder($position) {
		$sql = "SELECT	MAX(showOrder) AS showOrder
			FROM	wcf".WCF_N."_box
			WHERE	position = ?";
		$statement = WCF::getDB()->prepareStatement($sql, 1);
		$statement->execute([$position]);
		
		$row = $statement->fetchSingleRow();
		
		return (!$row['showOrder']) ? 1 : $row['showOrder'] + 1;
	}
	
	/**
	 * @inheritDoc
	 */
	protected function import(array $row, array $data) {
		// extract content
		$content = $data['content'];
		unset($data['content']);
		
		// updating boxes is only supported for 'system' type boxes, all other
		// types would potentially overwrite changes made by the user if updated
		if (!empty($row) && $row['boxType'] !== 'system') {
			$box = new Box(null, $row);
		}
		else {
			$box = parent::import($row, $data);
		}
		
		// store content for later import
		$this->content[$box->boxID] = $content;
		$this->boxes[$box->boxID] = ($box instanceof Box) ? new BoxEditor($box) : $box;
		
		return $box;
	}
	
	/**
	 * @inheritDoc
	 */
	protected function postImport() {
		if (!empty($this->content)) {
			$sql = "SELECT  COUNT(*) AS count
				FROM    wcf".WCF_N."_box_content
				WHERE   boxID = ?
					AND languageID IS NULL";
			$statement = WCF::getDB()->prepareStatement($sql);
			
			$sql = "INSERT IGNORE INTO	wcf".WCF_N."_box_content
							(boxID, languageID, title, content)
				VALUES			(?, ?, ?, ?)";
			$insertStatement = WCF::getDB()->prepareStatement($sql);
			
			WCF::getDB()->beginTransaction();
			foreach ($this->content as $boxID => $contentData) {
				$boxEditor = $this->boxes[$boxID];
				
				// expand non-i18n value
				if ($boxEditor->boxType === 'system' && count($contentData) === 1 && isset($contentData[''])) {
					foreach (LanguageFactory::getInstance()->getLanguages() as $language) {
						$insertStatement->execute([
							$boxID,
							$language->languageID,
							$contentData['']['title'],
							''
						]);
					}
					
					continue;
				}
				
				foreach ($contentData as $languageCode => $content) {
					$languageID = null;
					if ($languageCode != '') {
						$language = LanguageFactory::getInstance()->getLanguageByCode($languageCode);
						if ($language === null) continue;
						
						$languageID = $language->languageID;
					}
					
					if ($languageID === null) {
						$statement->execute([$boxID]);
						if ($statement->fetchSingleColumn()) continue;
					}
					
					$boxContent = isset($content['content']) ? $content['content'] : '';
					$insertStatement->execute([
						$boxID,
						$languageID,
						$content['title'],
						$boxContent
					]);
					
					if ($boxEditor->getDecoratedObject()->boxType === 'tpl') {
						$boxEditor->writeTemplate($languageID, $boxContent);
					}
				}
			}
			WCF::getDB()->commitTransaction();
		}
		
		if (empty($this->visibilityExceptions)) return;
		
		// get all boxes belonging to the identifiers
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("identifier IN (?)", [array_keys($this->visibilityExceptions)]);
		$conditions->add("packageID = ?", [$this->installation->getPackageID()]);
		
		$sql = "SELECT	*
			FROM	wcf".WCF_N."_box
			".$conditions;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($conditions->getParameters());
		
		/** @var Box[] $boxes */
		$boxes = $statement->fetchObjects(Box::class, 'identifier');
		
		// save visibility exceptions
		$sql = "DELETE FROM	wcf".WCF_N."_box_to_page
			WHERE		boxID = ?";
		$deleteStatement = WCF::getDB()->prepareStatement($sql);
		$sql = "INSERT IGNORE	wcf".WCF_N."_box_to_page
					(boxID, pageID, visible)
			VALUES		(?, ?, ?)";
		$insertStatement = WCF::getDB()->prepareStatement($sql);
		foreach ($this->visibilityExceptions as $boxIdentifier => $pages) {
			// delete old visibility exceptions
			$deleteStatement->execute([$boxes[$boxIdentifier]->boxID]);
			
			// get page ids
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('identifier IN (?)', [$pages]);
			$sql = "SELECT	pageID
				FROM	wcf".WCF_N."_page
				".$conditionBuilder;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			$pageIDs = $statement->fetchAll(\PDO::FETCH_COLUMN);
			
			// save page ids
			foreach ($pageIDs as $pageID) {
				$insertStatement->execute([$boxes[$boxIdentifier]->boxID, $pageID, $boxes[$boxIdentifier]->visibleEverywhere ? 0 : 1]);
			}
		}
	}
	
	/**
	 * @inheritDoc
	 * @since	3.1
	 */
	public static function getSyncDependencies() {
		return ['language', 'objectType'];
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	public function getAdditionalTemplateCode() {
		return WCF::getTPL()->fetch('__boxPipGui');
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	public function addFormFields(IFormDocument $form) {
		$tabContainter = TabMenuFormContainer::create('tabMenu');
		$form->appendChild($tabContainter);
		
		$dataTab = TabFormContainer::create('dataTab')
			->label('wcf.global.form.data');
		$tabContainter->appendChild($dataTab);
		$dataContainer = FormContainer::create('dataTabData');
		$dataTab->appendChild($dataContainer);
		
		$contentTab = TabFormContainer::create('contentTab')
			->label('wcf.acp.pip.box.content');
		$tabContainter->appendChild($contentTab);
		$contentContainer = FormContainer::create('contentTabContent');
		$contentTab->appendChild($contentContainer);
		
		$dataContainer->appendChildren([
			TextFormField::create('identifier')
				->label('wcf.acp.pip.box.identifier')
				->description('wcf.acp.pip.box.identifier.description')
				->required()
				->addValidator(ObjectTypePackageInstallationPlugin::getObjectTypeAlikeValueValidator(
					'wcf.acp.pip.box.identifier',
					4
				))
				->addValidator(new FormFieldValidator('uniqueness', function(TextFormField $formField) {
					if (
						$formField->getDocument()->getFormMode() === IFormDocument::FORM_MODE_CREATE ||
						$this->editedEntry->getAttribute('identifier') !== $formField->getValue()
					) {
						$pageList = new BoxList();
						$pageList->getConditionBuilder()->add('identifier = ?', [$formField->getValue()]);
						
						if ($pageList->countObjects() > 0) {
							$formField->addValidationError(
								new FormFieldValidationError(
									'notUnique',
									'wcf.acp.pip.box.identifier.error.notUnique'
								)
							);
						}
					}
				})),
			
			TextFormField::create('name')
				->label('wcf.acp.pip.box.name')
				->description('wcf.acp.pip.box.name.description')
				->required()
				->i18n()
				->i18nRequired()
				->languageItemPattern('__NONE__'),
			
			RadioButtonFormField::create('boxType')
				->label('wcf.acp.pip.box.boxType')
				->description('wcf.acp.pip.box.boxType.description')
				->options(array_combine(Box::$availableBoxTypes, Box::$availableBoxTypes)),
			
			SingleSelectionFormField::create('objectType')
				->label('wcf.acp.pip.box.objectType')
				->description('wcf.acp.pip.box.objectType.description')
				->required()
				->options(function() {
					$objectTypes = ObjectTypeCache::getInstance()->getObjectTypes('com.woltlab.wcf.boxController');
					
					$options = [];
					foreach ($objectTypes as $objectType) {
						$options[$objectType->objectType] = $objectType->objectType;
					}
					
					asort($options);
					
					return $options;
				}),
			
			SingleSelectionFormField::create('position')
				->label('wcf.acp.pip.box.position')
				->options(array_combine(Box::$availablePositions, Box::$availablePositions)),
			
			BooleanFormField::create('showHeader')
				->label('wcf.acp.pip.box.showHeader'),
			
			BooleanFormField::create('visibleEverywhere')
				->label('wcf.acp.pip.box.visibleEverywhere'),
			
			MultipleSelectionFormField::create('visibilityExceptions')
				->label('wcf.acp.pip.box.visibilityExceptions.hiddenEverywhere')
				->filterable()
				->options(function() {
					$pageNodeList = (new PageNodeTree())->getNodeList();
					
					$nestedOptions = [];
					/** @var PageNode $pageNode */
					foreach ($pageNodeList as $pageNode) {
						$nestedOptions[] = [
							'depth' => $pageNode->getDepth() - 1,
							'label' => $pageNode->name,
							'value' => $pageNode->identifier
						];
					}
					
					return $nestedOptions;
				}, true),
			
			ItemListFormField::create('cssClassName')
				->label('wcf.acp.pip.box.cssClassName')
				->description('wcf.acp.pip.box.cssClassName.description')
				->saveValueType(ItemListFormField::SAVE_VALUE_TYPE_SSV)
		]);
		
		$contentContainer->appendChildren([
			TitleFormField::create('title')
				->label('wcf.acp.pip.box.content.title')
				->required()
				->i18n()
				->i18nRequired()
				->languageItemPattern('__NONE__'),
			
			MultilineTextFormField::create('contentContent')
				->objectProperty('content')
				->label('wcf.acp.pip.box.content.content')
				->required()
				->i18n()
				->i18nRequired()
				->languageItemPattern('__NONE__')
		]);
		
		// add box controller-specific form fields 
		foreach (ObjectTypeCache::getInstance()->getObjectTypes('com.woltlab.wcf.boxController') as $objectType) {
			if (is_subclass_of($objectType->className, AbstractDatabaseObjectListBoxController::class)) {
				/** @var AbstractDatabaseObjectListBoxController $boxController */
				$boxController = new $objectType->className;
				
				$boxController->addPipGuiFormFields($form, $objectType->objectType);
			}
		}
		
		// add dependencies
		
		/** @var SingleSelectionFormField $boxType */
		$boxType = $dataContainer->getNodeById('boxType');
		
		$dataContainer->getNodeById('objectType')->addDependency(
			ValueFormFieldDependency::create('boxType')
				->field($boxType)
				->values(['system'])
		);
		
		$contentContainer->getNodeById('contentContent')->addDependency(
			ValueFormFieldDependency::create('pageType')
				->field($boxType)
				->values(['system'])
				->negate()
		);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function getElementData(\DOMElement $element, $saveData = false) {
		$data = [
			'boxType' => $element->getElementsByTagName('boxType')->item(0)->nodeValue,
			'content' => [],
			'identifier' => $element->getAttribute('identifier'),
			'name' => [],
			'originIsSystem' => 1,
			'packageID' => $this->installation->getPackageID(),
			'position' => $element->getElementsByTagName('position')->item(0)->nodeValue,
			'title' => []
		];
		
		/** @var \DOMElement $name */
		foreach ($element->getElementsByTagName('name') as $name) {
			$data['name'][LanguageFactory::getInstance()->getLanguageByCode($name->getAttribute('language'))->languageID] = $name->nodeValue;
		}
		
		foreach (['objectType'] as $optionalElementName) {
			$optionalElement = $element->getElementsByTagName($optionalElementName)->item(0);
			if ($optionalElement !== null) {
				$data[$optionalElementName] = $optionalElement->nodeValue;
			}
		}
		
		// TODO: rest
		
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
			'identifier' => 'wcf.acp.pip.box.identifier',
			'boxType' => 'wcf.acp.pip.box.boxType'
		]);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function sortDocument(\DOMDocument $document) {
		$this->sortImportDelete($document);
		
		$compareFunction = function(\DOMElement $element1, \DOMElement $element2) {
			return strcmp(
				$element1->getAttribute('identifier'),
				$element2->getAttribute('identifier')
			);
		};
		
		$this->sortChildNodes($document->getElementsByTagName('import'), $compareFunction);
		$this->sortChildNodes($document->getElementsByTagName('delete'), $compareFunction);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function writeEntry(\DOMDocument $document, IFormDocument $form) {
		$formData = $form->getData();
		
		if ($formData['data']['identifier'] === 'com.woltlab.wcf.MainMenu') {
			$formData['data']['boxPosition'] = 'mainMenu';
		}
		
		$box = $document->createElement($this->tagName);
		$box->setAttribute('identifier', $formData['data']['identifier']);
		
		foreach ($formData['name_i18n'] as $languageID => $name) {
			$nameElement = $document->createElement('name', $this->getAutoCdataValue($name));
			$nameElement->setAttribute('language', LanguageFactory::getInstance()->getLanguage($languageID)->languageCode);
			
			$box->appendChild($nameElement);
		}
		
		$box->appendChild($document->createElement('boxType', $formData['data']['boxType']));
		
		foreach ($formData['data'] as $property => $value) {
			if (!in_array($property, self::$reservedTags) && $value !== null) {
				$box->appendChild($document->createElement($property, (string)$value));
			}
		}
		
		foreach (LanguageFactory::getInstance()->getLanguages() as $language) {
			$content = null;
			
			foreach (['title', 'content'] as $property) {
				if (!empty($formData[$property . '_i18n'][$language->languageID])) {
					if ($content === null) {
						$content = $document->createElement('content');
						$content->setAttribute('language', $language->languageCode);
						
						$box->appendChild($content);
					}
					
					if ($property === 'content') {
						$contentContent = $document->createElement('content');
						$contentContent->appendChild(
							$document->createCDATASection(
								StringUtil::escapeCDATA(StringUtil::unifyNewlines(
									$formData[$property . '_i18n'][$language->languageID]
								))
							)
						);
						
						$content->appendChild($contentContent);
					}
					else {
						$content->appendChild(
							$document->createElement(
								$property,
								$formData[$property . '_i18n'][$language->languageID]
							)
						);
					}
				}
			}
		}
	}
}
