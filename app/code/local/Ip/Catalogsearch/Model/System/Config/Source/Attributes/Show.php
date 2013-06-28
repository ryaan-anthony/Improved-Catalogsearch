<?php
class Ip_Catalogsearch_Model_System_Config_Source_Attributes_Show 
{
	
	private $options = null;

	public function toOptionArray() 
	{
		if ($this->options === null) {
			$this->options = array();
			$collection = Mage::getResourceModel('catalog/product_attribute_collection');
			if ($collection) {
				$collection->addIsSearchableFilter()->addVisibleFilter();
				foreach ($collection->getItems() as $item) {
					$this->options[] = array(
						'value' => $item->getAttributeCode(),
						'label' => $item->getFrontendLabel(),
					);
				}
			}
		}
		return $this->options;
	}
}
