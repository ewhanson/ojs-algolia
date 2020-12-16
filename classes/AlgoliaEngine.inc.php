<?php

/**
 * @file plugins/generic/algolia/classes/AlgoliaEngine.inc.php
 *
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AlgoliaEngine
 * @ingroup plugins_generic_algolia_classes
 *
 * @brief This handles the addition and deletion of object/records in Algolia.
 */

require_once dirname(dirname(__FILE__)) . '/libs/vendor/autoload.php';

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;

class AlgoliaEngine {

	protected $settings = null;
	protected $client = null;
	protected $index = null;

	/**
	 * AlgoliaEngine constructor.
	 *
	 * @param $settings
	 * @param $search_only
	 */
	public function __construct($settings) {
		$this->settings = $settings;
		$this->index = $settings['index'];

		try {
			$this->client = SearchClient::create($this->settings['app_id'], $this->settings['api_key']);
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * @return SearchClient|null
	 */
	public function get_client() {
		return $this->client;
	}

	/**
	 * Retrieves all the indexes available
	 *
	 * @return array|string
	 */
	public function get_indexes() {
		try {
			$indexes = $this->client->listIndices();

			$data = array();
			foreach ($indexes['items'] as $index) {
				$data[] = $index['name'];
			}

			return $data;
		} catch (AlgoliaException $e) {
			return false;
		}
	}

	/**
	 * Adds/Updates objects in the index
	 *
	 * @param $index_name
	 * @param $objects
	 * @return bool|string
	 */
	public function index($objects) {
		// Adds API v2 required field 'indexName' to objects
		foreach ($objects as &$object) {
			$object['indexName'] = $this->index;
		}

		try {
			$success = $this->client->multipleBatch($objects);
		} catch (AlgoliaException $e) {
			return $e->getMessage();
		}

		return $success;
	}

	/**
	 * Deletes an object from the index
	 *
	 * @param $index_name
	 * @param $objects
	 * @return bool
	 */
	public function delete($objects) {
		// Adds API v2 required field 'indexName' to objects
		foreach ($objects as &$object) {
			$object['indexName'] = $this->index;
		}

		try {
			$this->client->multipleBatch($objects);
		} catch (AlgoliaException $e) {
			return $e->getMessage();
		}

		return true;
	}

	/**
	 * Deletes all objects in the specified index
	 *
	 * @param $index_name
	 * @return string
	 */
	public function clear_index() {
		// TODO: Checked
		$index = $this->client->initIndex($this->index);

		try {
			$index->clearObjects();
		} catch (AlgoliaException $e) {
			return $e->getMessage();
		}
	}

	/**
	 * Deletes an object by distictId
	 *
	 * @param  $distinctId
	 * @return bool
	 */
	public function deleteByDistinctId($distinctId) {
		// TODO: Checked
		$index = $this->client->initIndex($this->index);

		try {
			// TODO: See if should use faceted filters or regular filters
//            $index->deleteBy([
//                'facetFilters' => ['distinctId:' . $distinctId],
//            ]);
			$index->deleteBy([
				'filters' => 'distinctId:' . $distinctId,
			]);
		} catch (AlgoliaException $e) {
			return $e->getMessage();
		}

		return true;
	}
}
