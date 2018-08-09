<?php
namespace wcf\system\package\plugin;
use wcf\data\core\object\CoreObjectEditor;
use wcf\data\core\object\CoreObjectList;
use wcf\system\cache\builder\CoreObjectCacheBuilder;
use wcf\system\devtools\pip\IDevtoolsPipEntryList;
use wcf\system\devtools\pip\IGuiPackageInstallationPlugin;
use wcf\system\devtools\pip\TXmlGuiPackageInstallationPlugin;
use wcf\system\form\builder\container\FormContainer;
use wcf\system\form\builder\field\ClassNameFormField;
use wcf\system\form\builder\field\validation\FormFieldValidationError;
use wcf\system\form\builder\field\validation\FormFieldValidator;
use wcf\system\form\builder\IFormDocument;
use wcf\system\SingletonFactory;
use wcf\system\WCF;

/**
 * Installs, updates and deletes core objects.
 * 
 * @author	Alexander Ebert
 * @copyright	2001-2018 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	WoltLabSuite\Core\System\Package\Plugin
 */
class CoreObjectPackageInstallationPlugin extends AbstractXMLPackageInstallationPlugin implements IGuiPackageInstallationPlugin {
	use TXmlGuiPackageInstallationPlugin;
	
	/**
	 * @inheritDoc
	 */
	public $className = CoreObjectEditor::class;
	
	/**
	 * @inheritDoc
	 */
	protected function handleDelete(array $items) {
		$sql = "DELETE FROM	wcf".WCF_N."_".$this->tableName."
			WHERE		objectName = ?
					AND packageID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		foreach ($items as $item) {
			$statement->execute([
				$item['attributes']['name'],
				$this->installation->getPackageID()
			]);
		}
	}
	
	/**
	 * @inheritDoc
	 */
	protected function prepareImport(array $data) {
		return [
			'objectName' => $data['elements']['objectname']
		];
	}
	
	/**
	 * @inheritDoc
	 */
	protected function findExistingItem(array $data) {
		$sql = "SELECT	*
			FROM	wcf".WCF_N."_".$this->tableName."
			WHERE	objectName = ?
				AND packageID = ?";
		$parameters = [
			$data['objectName'],
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
	protected function cleanup() {
		CoreObjectCacheBuilder::getInstance()->reset();
	}
	
	/**
	 * @inheritDoc
	 * @since	3.1
	 */
	public static function getSyncDependencies() {
		return [];
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	public function addFormFields(IFormDocument $form) {
		/** @var FormContainer $dataContainer */
		$dataContainer = $form->getNodeById('data');
		
		$dataContainer->appendChild(
			ClassNameFormField::create('objectName')
				->objectProperty('objectname')
				->parentClass(SingletonFactory::class)
				->addValidator(new FormFieldValidator('uniqueness', function(ClassNameFormField $formField) {
					if (
						$formField->getDocument()->getFormMode() === IFormDocument::FORM_MODE_CREATE ||
						$this->editedEntry->getElementsByTagName('objectname')->item(0)->nodeValue !== $formField->getValue()
					) {
						$coreObjectList = new CoreObjectList();
						$coreObjectList->getConditionBuilder()->add('objectName <> ?', [$formField->getValue()]);
						
						if ($coreObjectList->countObjects() > 0) {
							$formField->addValidationError(
								new FormFieldValidationError(
									'notUnique',
									'wcf.acp.pip.coreObject.objectName.error.notUnique'
								)
							);
						}
					}
				}))
		);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function getElementData(\DOMElement $element, $saveData = false) {
		return [
			'objectName' => $element->getElementsByTagName('objectname')->item(0)->nodeValue,
			'packageID' => $this->installation->getPackage()->packageID
		];
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	public function getElementIdentifier(\DOMElement $element) {
		return sha1($element->getElementsByTagName('objectname')->item(0)->nodeValue);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function setEntryListKeys(IDevtoolsPipEntryList $entryList) {
		$entryList->setKeys([
			'objectName' => 'wcf.form.field.className'
		]);
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function sortDocument(\DOMDocument $document) {
		
	}
	
	/**
	 * @inheritDoc
	 * @since	3.2
	 */
	protected function writeEntry(\DOMDocument $document, IFormDocument $form) {
		$data = $form->getData()['data'];
		
		$coreObject = $document->createElement($this->tagName);
		
		$coreObject->appendChild($document->createElement('objectname', $data['objectname']));
		
		$document->getElementsByTagName('import')->item(0)->appendChild($coreObject);
		
		return $coreObject;
	}
}
