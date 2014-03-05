<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 AOE media GmbH <dev@aoemedia.de>
*  All rights reserved
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Controller that handles database actions of the t3deploy process inside TYPO3.
 *
 * @package t3deploy
 * @author Oliver Hader <oliver.hader@aoemedia.de>
 *
 */
class tx_t3deploy_databaseController {
	/*
	 * List of all possible update types:
	 *	+ add, change, drop, create_table, change_table, drop_table, clear_table
	 * List of all sensible update types:
	 *	+ add, change, create_table, change_table
	 */
	const UpdateTypes_List = 'add,change,create_table,change_table';
	const RemoveTypes_list = 'drop,drop_table,clear_table';

	/**
	 * @var t3lib_install|t3lib_install_Sql
	 */
	protected $install;

	/**
	 * @var \TYPO3\CMS\Core\Compatibility\LoadedExtensionsArray
	 */
	protected $loadedExtensions;

	/**
	 * @var array
	 */
	protected $consideredTypes;

	/**
	 * Creates this object.
	 */
	public function __construct() {

		if ( method_exists('t3lib_div', 'int_from_ver') && t3lib_div::int_from_ver(TYPO3_version) < 4007001) {
			$this->install = t3lib_div::makeInstance('t3lib_install');
		} else {
			$this->install = t3lib_div::makeInstance('t3lib_install_Sql');
		}

		$this->setLoadedExtensions($GLOBALS['TYPO3_LOADED_EXT']);
		$this->setConsideredTypes($this->getUpdateTypes());
	}

	/**
	 * Sets information concerning all loaded TYPO3 extensions.
	 *
	 * @param \TYPO3\CMS\Core\Compatibility\LoadedExtensionsArray $loadedExtensions
	 * @return void
	 */
	public function setLoadedExtensions(\TYPO3\CMS\Core\Compatibility\LoadedExtensionsArray $loadedExtensions) {
		$this->loadedExtensions = $loadedExtensions;
	}

	/**
	 * Sets the types condirered to be executed (updates and/or removal).
	 *
	 * @param array $consideredTypes
	 * @return void
	 * @see updateStructureAction()
	 */
	public function setConsideredTypes(array $consideredTypes) {
		$this->consideredTypes = $consideredTypes;
	}

	/**
	 * Adds considered types.
	 *
	 * @param array $consideredTypes
	 * @return void
	 * @see updateStructureAction()
	 */
	public function addConsideredTypes(array $consideredTypes) {
		$this->consideredTypes = array_unique(
			array_merge($this->consideredTypes, $consideredTypes)
		);
	}

	/**
	 * Updates the database structure.
	 *
	 * @param array $arguments Optional arguemtns passed to this action
	 * @return string
	 */
	public function updateStructureAction(array $arguments) {
		$isExecuteEnabled = (isset($arguments['--execute']) || isset($arguments['-e']));
		$isRemovalEnabled = (isset($arguments['--remove']) || isset($arguments['-r']));
		$isModifyKeysEnabled = isset($arguments['--drop-keys']);

		$result = $this->executeUpdateStructureUntilNoMoreChanges($arguments, $isModifyKeysEnabled);

		if(isset($arguments['--dump-file'])) {
			$dumpFileName = $arguments['--dump-file'][0];
			if(!file_exists(dirname($dumpFileName))) {
				throw new InvalidArgumentException(sprintf(
					'directory %s does not exist or is not readable', dirname($dumpFileName)
				));
			}
			if(file_exists($dumpFileName) && !is_writable($dumpFileName)) {
				throw new InvalidArgumentException(sprintf(
					'file %s is not writable', $dumpFileName
				));
			}
			file_put_contents($dumpFileName, $result);
			$result = sprintf("Output written to %s\n", $dumpFileName);
		}

		if ($isExecuteEnabled) {
			$result .= ($result ? PHP_EOL : '') . $this->executeUpdateStructureUntilNoMoreChanges($arguments, $isRemovalEnabled);
		}

		return $result;

	}

	/**
	 * call executeUpdateStructure until there are no more changes.
	 *
	 * The install tool sometimes relies on the user hitting the "update" button multiple times. This method
	 * encapsulates that behaviour.
	 *
	 * @see executeUpdateStructure()
	 * @param array $arguments
	 * @param bool $allowKeyModifications
	 * @return string
	 */
	protected function executeUpdateStructureUntilNoMoreChanges(array $arguments, $allowKeyModifications = FALSE) {
		$result = '';
		$iteration = 1;
		$loopResult = '';
		do {
			$previousLoopResult = $loopResult;
			$loopResult = $this->executeUpdateStructure($arguments, $allowKeyModifications);
			if($loopResult == $previousLoopResult) {
				break;
			}

			$result .= sprintf("\n# Iteration %d\n%s", $iteration++, $loopResult);

			if($iteration > 10) {
				$result .= "\nGiving up after 10 iterations.";
				break;
			}
		} while(!empty($loopResult));

		return $result;
	}

	/**
	 * Executes the database structure updates.
	 *
	 * @param array $arguments Optional arguemtns passed to this action
	 * @param boolean $allowKeyModifications Whether to allow key modifications
	 * @return string
	 */
	protected function executeUpdateStructure(array $arguments, $allowKeyModifications = FALSE) {
		$result = '';

		$isExcuteEnabled = (isset($arguments['--execute']) || isset($arguments['-e']));
		$isRemovalEnabled = (isset($arguments['--remove']) || isset($arguments['-r']));
		$isVerboseEnabled = (isset($arguments['--verbose']) || isset($arguments['-v']));
		$database = (isset($arguments['--database']) && $arguments['--database'] ? $arguments['--database'] : TYPO3_db);

		$changes = $this->install->getUpdateSuggestions(
			$this->getStructureDifferencesForUpdate($database, $allowKeyModifications)
		);

		if ($isRemovalEnabled) {
				// Disable the delete prefix, thus tables and fields can be removed directly:
			if ( method_exists('t3lib_div', 'int_from_ver') && t3lib_div::int_from_ver(TYPO3_version) < 4007001) {
				$this->install->deletedPrefixKey = '';
			} else {
				$this->install->setDeletedPrefixKey('');
			}
				// Add types considered for removal:
			$this->addConsideredTypes($this->getRemoveTypes());
				// Merge update suggestions:
			$removals = $this->install->getUpdateSuggestions(
				$this->getStructureDifferencesForRemoval($database, $allowKeyModifications),
				'remove'
			);
			$changes = array_merge($changes, $removals);
		}

		if ($isExcuteEnabled || $isVerboseEnabled) {
			$statements = array();

			// Concatenates all statements:
			foreach ($this->consideredTypes as $consideredType) {
				if (isset($changes[$consideredType]) && is_array($changes[$consideredType])) {
					$statements += $changes[$consideredType];
				}
			}

			$statements = $this->sortStatements($statements);

			if ($isExcuteEnabled) {
				foreach ($statements as $statement) {
					$GLOBALS['TYPO3_DB']->admin_query($statement);
				}
			}

			if ($isVerboseEnabled) {
				$result = implode(PHP_EOL, $statements);
			}
		}

		$this->checkChangesSyntax($result);

		return $result;
	}

	/**
	 * performs some basic checks on the database changes to identify most common errors
	 *
	 * @param string $changes the changes to check
	 * @throws Exception if the file seems to contain bad data
	 */
	protected function checkChangesSyntax($changes) {
		if (strlen($changes) < 10) return;
		$checked = substr(ltrim($changes), 0, 10);
		if ($checked != trim(strtoupper($checked))) {
			throw new Exception('Changes for schema_up seem to contain weird data, it starts with this:'.PHP_EOL.substr($changes, 0, 200).PHP_EOL.'=================================='.PHP_EOL.'If the file is ok, please add your conditions to file res/extensions/t3deploy/classes/class.tx_t3deploy_databaseController.php in t3deploy.');
		}
	}

	/**
	 * Removes key modifications that will cause errors.
	 *
	 * @param array $differences The differneces to be cleaned up
	 * @return array The cleaned differences
	 */
	protected function removeKeyModifications(array $differences) {
		$differences = $this->unsetSubKey($differences, 'extra', 'keys', 'whole_table');
		$differences = $this->unsetSubKey($differences, 'diff', 'keys');

		return $differences;
	}

	/**
	 * Unsets a subkey in a given differences array.
	 *
	 * @param array $differences
	 * @param string $type e.g. extra or diff
	 * @param string $subKey e.g. keys or fields
	 * @param string $exception e.g. whole_table that stops the removal
	 * @return array
	 */
	protected function unsetSubKey(array $differences, $type, $subKey, $exception = '') {
		if (isset($differences[$type])) {
			foreach ($differences[$type] as $table => $information) {
				$isException = ($exception && isset($information[$exception]) && $information[$exception]);
				if (isset($information[$subKey]) && $isException === FALSE) {
					unset($differences[$type][$table][$subKey]);
				}
			}
		}

		return $differences;
	}

	/**
	 * Gets the differences in the database structure by comparing
	 * the current structure with the SQL definitions of all extensions
	 * and the TYPO3 core in t3lib/stddb/tables.sql.
	 *
	 * This method searches for fields/tables to be added/updated.
	 *
	 * @param string $database
	 * @param boolean $allowKeyModifications Whether to allow key modifications
	 * @return array The database statements to update the structure
	 */
	protected function getStructureDifferencesForUpdate($database, $allowKeyModifications = FALSE) {
		$differences = $this->install->getDatabaseExtra(
			$this->getDefinedFieldDefinitions(),
			$this->install->getFieldDefinitions_database($database)
		);

		if (!$allowKeyModifications) {
			$differences = $this->removeKeyModifications($differences);
		}

		return $differences;
	}

	/**
	 * Gets the differences in the database structure by comparing
	 * the current structure with the SQL definitions of all extensions
	 * and the TYPO3 core in t3lib/stddb/tables.sql.
	 *
	 * This method searches for fields/tables to be removed.
	 *
	 * @param string $database
	 * @param boolean $allowKeyModifications Whether to allow key modifications
	 * @return array The database statements to update the structure
	 */
	protected function getStructureDifferencesForRemoval($database, $allowKeyModifications = FALSE) {
		$differences = $this->install->getDatabaseExtra(
			$this->install->getFieldDefinitions_database($database),
			$this->getDefinedFieldDefinitions()
		);

		if (!$allowKeyModifications) {
			$differences = $this->removeKeyModifications($differences);
		}

		return $differences;
	}

	/**
	 * Gets the defined field definitions from the ext_tables.sql files.
	 *
	 * @return array The accordant definitions
	 */
	protected function getDefinedFieldDefinitions() {
		$cacheTables = '';

		if (class_exists('t3lib_cache') && method_exists(t3lib_cache, 'getDatabaseTableDefinitions')) {
			$cacheTables = t3lib_cache::getDatabaseTableDefinitions();
		}

		if (method_exists($this->install, 'getFieldDefinitions_fileContent')) {
			$content = $this->install->getFieldDefinitions_fileContent (
				implode(chr(10), $this->getAllRawStructureDefinitions()) . $cacheTables
			);
		} else {
			$content = $this->install->getFieldDefinitions_sqlContent (
				implode(chr(10), $this->getAllRawStructureDefinitions()) . $cacheTables
			);
		}

		return $content;
	}

	/**
	 * Gets all structure definitions of extensions the TYPO3 Core.
	 *
	 * @return array All structure definitions
	 */
	protected function getAllRawStructureDefinitions() {
		/** @var TYPO3\CMS\Extbase\Object\ObjectManager $objectManager */
		$objectManager = t3lib_div::makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager');
		/** @var \TYPO3\CMS\Install\Service\SqlSchemaMigrationService $schemaMigrationService */
		$schemaMigrationService = $objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlSchemaMigrationService');
		/** @var \TYPO3\CMS\Install\Service\SqlExpectedSchemaService $expectedSchemaService */
		$expectedSchemaService = $objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlExpectedSchemaService');

		$expectedSchemaString = $expectedSchemaService->getTablesDefinitionString(TRUE);
		$rawDefinitions = $schemaMigrationService->getStatementArray($expectedSchemaString, TRUE);

		return $rawDefinitions;
	}

	/**
	 * Gets the defined update types.
	 *
	 * @return array
	 */
	protected function getUpdateTypes() {
		return t3lib_div::trimExplode(',', self::UpdateTypes_List, TRUE);
	}

	/**
	 * Gets the defined remove types.
	 *
	 * @return array
	 */
	protected function getRemoveTypes() {
		return t3lib_div::trimExplode(',', self::RemoveTypes_list, TRUE);
	}

	/**
	 * sorts the statements in an array
	 *
	 * @param array $statements
	 * @return array
	 */
	protected function sortStatements($statements) {
		$newStatements = array();
		foreach($statements as $key=>$statement) {
			if($this->isDropKeyStatement($statement)) {
				$newStatements[$key] = $statement;
			}
		}
		foreach($statements as $key=>$statement) {
			// writing the statement again, does not change its position
			// this saves a condition check
			$newStatements[$key] = $statement;
		}

		return $newStatements;
	}

	/**
	 * returns true if the given statement looks as if it drops a (primary) key
	 *
	 * @param $statement
	 * @return bool
	 */
	protected function isDropKeyStatement($statement) {
		return strpos($statement, ' DROP ') !== FALSE && strpos($statement, ' KEY') !== FALSE;
	}
}
