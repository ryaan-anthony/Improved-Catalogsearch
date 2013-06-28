<?php

$installer = $this;
$connection = $installer->getConnection();

$installer->startSetup();

$installer->run("
DROP TABLE IF EXISTS `{$installer->getTable('ipcatalogsearch_fulltext')}`;
CREATE TABLE `{$installer->getTable('ipcatalogsearch_fulltext')}` (
  `product_id` int(10) unsigned NOT NULL,
  `store_id` smallint(5) unsigned NOT NULL,
  `data_index` longtext NOT NULL,
  `data_index_1` longtext NOT NULL,
  `data_index_2` longtext NOT NULL,
  `data_index_3` longtext NOT NULL,
  PRIMARY KEY (`product_id`,`store_id`),
  FULLTEXT KEY `data_index` (`data_index`),
  FULLTEXT KEY `data_index_1` (`data_index_1`),
  FULLTEXT KEY `data_index_2` (`data_index_2`),
  FULLTEXT KEY `data_index_3` (`data_index_3`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
");

$installer->endSetup();
