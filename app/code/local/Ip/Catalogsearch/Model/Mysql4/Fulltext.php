<?php
class Ip_Catalogsearch_Model_Mysql4_Fulltext extends Mage_CatalogSearch_Model_Mysql4_Fulltext 
{

	protected function _construct() 
	{
		$this->_init('ipcatalogsearch/fulltext', 'product_id');
        $this->_engine = Mage::helper('catalogsearch')->getEngine();
	}

	/**
	 * Prepare results for query
	 *
	 * @param Mage_CatalogSearch_Model_Fulltext $object
	 * @param string $queryText
	 * @param Mage_CatalogSearch_Model_Query $query
	 * @return Mage_CatalogSearch_Model_Mysql4_Fulltext
	 */
	public function prepareResult($object, $queryText, $query) 
	{
		if (!$query->getIsProcessed()) {
			$searchType = $object->getSearchType($query->getStoreId());

			$stringHelper = Mage::helper('core/string');
			/* @var $stringHelper Mage_Core_Helper_String */

			$bind = array(':query'     => $queryText);
			$like = array();

			$fulltextCond   = '';
			$likeCond       = '';
			$separateCond   = '';

			if ($searchType == Mage_CatalogSearch_Model_Fulltext::SEARCH_TYPE_LIKE
					|| $searchType == Mage_CatalogSearch_Model_Fulltext::SEARCH_TYPE_COMBINE) {
				$words = $stringHelper->splitWords($queryText, true, $query->getMaxQueryWords());
				$likeI = 0;
				foreach ($words as $word) {
					$like[] = '`s`.`data_index` LIKE :likew' . $likeI;
					$bind[':likew' . $likeI] = '%' . $word . '%';
					$likeI ++;
				}
				if ($like) {
					$likeCond = '(' . join(' AND ', $like) . ')';
				}
			}
			if ($searchType == Mage_CatalogSearch_Model_Fulltext::SEARCH_TYPE_FULLTEXT
					|| $searchType == Mage_CatalogSearch_Model_Fulltext::SEARCH_TYPE_COMBINE) {
				$fulltextCond = 'MATCH (`s`.`data_index`) AGAINST (:query IN BOOLEAN MODE)';
			}
			if ($searchType == Mage_CatalogSearch_Model_Fulltext::SEARCH_TYPE_COMBINE && $likeCond) {
				$separateCond = ' OR ';
			}

			$relevanceExpression = '(MATCH (`s`.`data_index`) AGAINST (:query))';

			// Add priority attributes
			for ($i = 0; $i < self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES; $i++) {
				$weight = $this->_getPriorityWeight($i);
				if ($weight != 0) {
					$relevanceExpression .= '+('.intval($weight).'*(MATCH(`s`.`data_index_'.($i+1).'`) AGAINST (:query)))';
				}
			}

			if ($this->_getNegateRelevance()) {
				$relevanceExpression = '-('.$relevanceExpression.')';
			}

			$sql = sprintf(
				"INSERT INTO `{$this->getTable('catalogsearch/result')}` 
				(SELECT '%d', `s`.`product_id`, %s 
				FROM `{$this->getMainTable()}` AS `s` INNER JOIN `{$this->getTable('catalog/product')}` AS `e`
				ON `e`.`entity_id`=`s`.`product_id` WHERE (%s%s%s) AND `s`.`store_id`='%d')
				ON DUPLICATE KEY UPDATE `relevance`=VALUES(`relevance`)",
				$query->getId(),
				$relevanceExpression,
				$fulltextCond,
				$separateCond,
				$likeCond,
				$query->getStoreId()
			);

			$this->_getWriteAdapter()->query($sql, $bind);

			//@TODO $this->computeRelevances($object, $queryText, $query);

			$query->setIsProcessed(1);
		}

		return $this;
	}

	private function computeRelevances($object, $queryText, $query) 
	{
		$read = Mage::getSingleton('core/resource')->getConnection('core_read');
		$tableName = $this->getTable('catalogsearch/result');

		$select = $read->select()
			->from($tableName)
			->where('query_id = ?', $query->getId());
		$rows = $read->fetchAll($select);

		if (is_array($rows)) {
			$write = Mage::getSingleton('core/resource')->getConnection('core_write');
			foreach ($rows as $row) {
				$productId = $row['product_id'];
				$relevance = $this->getRelevance($queryText, $query, $productId);
				if ($relevance !== null) {
					$where = array(
						$write->quoteInto('query_id = ?', $query->getId()),
						$write->quoteInto('product_id = ?', $productId),
					);
					$write->update($tableName, array('relevance' => $relevance), $where);
				}
			}
		}
	}

	/**
	 * Get result relevance
	 *
	 * @param string $queryText
	 * @param Mage_CatalogSearch_Model_Query $query
	 * @param int $productId
	 *
	 * @return null|int
	 */
	private function getRelevance($queryText, $query, $productId) 
	{
		return;
	}

	const NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES = 3;

	/**
	 * Regenerate search index for specific store
	 *
	 * @param int $storeId Store View Id
	 * @param int|array $productIds Product Entity Id
	 * @return Mage_CatalogSearch_Model_Mysql4_Fulltext
	 */
	protected function _rebuildStoreIndex($storeId, $productIds = null) 
	{
		$this->cleanIndex($storeId, $productIds);

		// preparesearchable attributes
		$staticFields   = array();
		foreach ($this->_getSearchableAttributes('static') as $attribute) {
			$staticFields[] = $attribute->getAttributeCode();
		}
		$dynamicFields  = array(
			'int'       => array_keys($this->_getSearchableAttributes('int')),
			'varchar'   => array_keys($this->_getSearchableAttributes('varchar')),
			'text'      => array_keys($this->_getSearchableAttributes('text')),
			'decimal'   => array_keys($this->_getSearchableAttributes('decimal')),
			'datetime'  => array_keys($this->_getSearchableAttributes('datetime')),
		);

		// status and visibility filter
		$visibility     = $this->_getSearchableAttribute('visibility');
		$status         = $this->_getSearchableAttribute('status');
		$visibilityVals = Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds();
		$statusVals     = Mage::getSingleton('catalog/product_status')->getVisibleStatusIds();

		$lastProductId = 0;
		while (true) {
			$products = $this->_getSearchableProducts($storeId, $staticFields, $productIds, $lastProductId);
			if (!$products) {
				break;
			}

			$productAttributes  = array();
			$productRelations   = array();
			foreach ($products as $productData) {
				$lastProductId = $productData['entity_id'];
				$productAttributes[$productData['entity_id']] = $productData['entity_id'];
				$productChilds = $this->_getProductChildIds($productData['entity_id'], $productData['type_id']);
				$productRelations[$productData['entity_id']] = $productChilds;
				if ($productChilds) {
					foreach ($productChilds as $productChildId) {
						$productAttributes[$productChildId] = $productChildId;
					}
				}
			}

			$productIndexes     = array();
			$priorityProductIndexes = array_fill(0, self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES, array());
			$productAttributes  = $this->_getProductAttributes($storeId, $productAttributes, $dynamicFields);
			foreach ($products as $productData) {
				if (!isset($productAttributes[$productData['entity_id']])) {
					continue;
				}
				$protductAttr = $productAttributes[$productData['entity_id']];
				if (!isset($protductAttr[$visibility->getId()]) || !in_array($protductAttr[$visibility->getId()], $visibilityVals)) {
					continue;
				}
				if (!isset($protductAttr[$status->getId()]) || !in_array($protductAttr[$status->getId()], $statusVals)) {
					continue;
				}

				$productIndex = array(
															$productData['entity_id'] => $protductAttr
															);
				if ($productChilds = $productRelations[$productData['entity_id']]) {
					foreach ($productChilds as $productChildId) {
						if (isset($productAttributes[$productChildId])) {
							$productIndex[$productChildId] = $productAttributes[$productChildId];
						}
					}
				}

				$index = $this->_prepareProductIndex($productIndex, $productData, $storeId);
				$productIndexes[$productData['entity_id']] = $index;
				//$this->_saveProductIndex($productData['entity_id'], $storeId, $index);

				for ($i = 0; $i < self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES; $i++) {
					$priorityProductIndexes[$i][$productData['entity_id']] = $this->_prepareProductIndexPriority($productIndex, $productData, $storeId, $i);
				}
			}
			$this->_saveProductIndexes($storeId, $productIndexes, $priorityProductIndexes);
		}

		$this->resetSearchResults();

		return $this;
	}

	protected $_priorityAttributes = null;

	protected function _getPriorityAttributes($priorityIndex) 
	{
		if (is_null($this->_priorityAttributes)) {
			$this->_priorityAttributes = array_fill(0, self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES, array());
			$entityType = $this->getEavConfig()->getEntityType('catalog_product');

			for ($i = 0; $i < self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES; $i++) {
				$attributeCodes = explode(',', Mage::getStoreConfig('catalog/ipcatalogsearch/priority_attributes_'.($i+1)));

				foreach ($attributeCodes as $attributeCode) {
					$attribute = $this->getEavConfig()->getAttribute($entityType, $attributeCode);

					$this->_priorityAttributes[$i][$attribute->getId()] = $attribute;
				}
			}
		}

		return isset($this->_priorityAttributes[$priorityIndex]) ? $this->_priorityAttributes[$priorityIndex] : null;
	}

	protected function _prepareProductIndexPriority($indexData, $productData, $storeId, $priorityIndex) 
	{
		$productIndex = array();

		$attributeCode = 'name';
		$entityType = $this->getEavConfig()->getEntityType('catalog_product');
		$attribute = $this->getEavConfig()->getAttribute($entityType, $attributeCode);

		$priorityAttributes = $this->_getPriorityAttributes($priorityIndex);

		foreach ($indexData as $attributeData) {
			foreach ($attributeData as $attributeId => $attributeValue) {
				if (is_array($priorityAttributes) && isset($priorityAttributes[$attributeId])) {
					if ($value = $this->_getAttributeValue($attributeId, $attributeValue, $storeId)) {
						$productIndex[] = $value;
					}
				}
			}
		}
		return join($this->_separator, $productIndex);
	}

	protected $_priorityWeights = null;

	protected function _getPriorityWeight($index) 
	{
		if (is_null($this->_priorityWeights)) {
			$this->_priorityWeights = array_fill(0, self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES, array());
			for ($i = 0; $i < self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES; $i++) {
				$value = Mage::getStoreConfig('catalog/ipcatalogsearch/priority_weight_'.($i+1));
				$this->_priorityWeights[$i] = intval($value);
			}
		}
		return $this->_priorityWeights[$index];
	}

	protected function _getNegateRelevance() 
	{
		$value = Mage::getStoreConfig('catalog/ipcatalogsearch/negate_relevance');
		return (bool)$value;
	}

	/**
	 * Save Multiply Product indexes
	 *
	 * @param int $storeId
	 * @param array $productIndexes
	 * @return Mage_CatalogSearch_Model_Mysql4_Fulltext
	 */
	protected function _saveProductIndexes($storeId, $productIndexes) 
	{
		// Get extra indexes from arguments
		// $productIndexes = array($productIndexes);
		$priorityProductIndexes = (func_num_args() > 2) ? func_get_arg(2) : null;

		$values = array();
		$bind   = array();
		foreach ($productIndexes as $productId => &$index) {
			$row = array(
				$this->_getWriteAdapter()->quoteInto('?', $productId),
				$this->_getWriteAdapter()->quoteInto('?', $storeId),
				'?', // data_index
			);
			$bind[] = $index;

			for ($i = 0; $i < self::NUMBER_OF_PRIORITY_SEARCH_ATTRIBUTES; $i++) {
				$row[] = '?';
				$bind[] = ($priorityProductIndexes && isset($priorityProductIndexes[$i], $priorityProductIndexes[$i][$productId])) ? $priorityProductIndexes[$i][$productId] : '';
			}

			$values[] = '('.implode(',', $row).')';
		}

		// var_dump(array('values' => $values,
		// 							 'bind' => $bind,
		// 							 ));

		if ($values) {
			$sql = "REPLACE INTO `{$this->getMainTable()}`(product_id, store_id, data_index, data_index_1, data_index_2, data_index_3) VALUES"
				. join(',', $values);
			$this->_getWriteAdapter()->query($sql, $bind);
		}

		return $this;
	}

}