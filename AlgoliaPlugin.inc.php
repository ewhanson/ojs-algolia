<?php

/**
 * @file plugins/generic/algolia/AlgoliaPlugin.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AlgoliaPlugin
 * @ingroup plugins_generic_algolia
 *
 * @brief Algolia plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('plugins.generic.algolia.classes.AlgoliaService');

class AlgoliaPlugin extends GenericPlugin {
	var $_algoliaService;

	//
	// Getters and Setters
	//
	/**
	 * Get the Algolia service.
	 *
	 * @return AlgoliaService
	 */
	function getAlgoliaService() {
		return $this->_algoliaService;
	}

	//
	// Implement template methods from Plugin.
	//
	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return $success;

		if ($success && $this->getEnabled($mainContextId)) {
			$this->_registerTemplateResource();

			// Register hooks for plugin specific publication settings
			HookRegistry::register('Schema::get::publication', array($this, 'addToPublicationSchema'));
			HookRegistry::register('Publication::getMany::queryBuilder', array($this, 'modifyPublicationQueryBuilder'));
			HookRegistry::register('Publication::getMany::queryObject', array($this, 'modifyPublicationQueryObject'));

			// Register callbacks (controller-level).
			HookRegistry::register('ArticleSearchIndex::articleMetadataChanged', array($this, 'callbackArticleMetadataChanged'));
			HookRegistry::register('ArticleSearchIndex::articleDeleted', array($this, 'callbackArticleDeleted'));
			HookRegistry::register('ArticleSearchIndex::articleChangesFinished', array($this, 'callbackArticleChangesFinished'));
			HookRegistry::register('ArticleSearchIndex::submissionFileDeleted', array($this, 'callbackSubmissionFileDeleted'));
			HookRegistry::register('Publication::publish', array($this, 'callbackPublicationStatusChanged'));
			HookRegistry::register('Publication::unpublish', array($this, 'callbackPublicationStatusChanged'));

			HookRegistry::register('ArticleSearchIndex::rebuildIndex', array($this, 'callbackRebuildIndex'));

			// for the front end
			$searchOnlyKey = $this->getSetting(CONTEXT_SITE, 'searchOnlyKey');

			// call the algolia service
			$this->_algoliaService = new AlgoliaService(
				array(
					"api_key" => $this->getSetting(CONTEXT_SITE, 'adminKey'),
					"app_id" => $this->getSetting(CONTEXT_SITE, 'appId'),
					"index" => $this->getSetting(CONTEXT_SITE, 'index'),
				)
			);
		}

		return $success;
	}

	/**
	 * @see Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.algolia.displayName');
	}

	/**
	 * @see Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.algolia.description');
	}

	/**
	 * @see Plugin::getInstallSitePluginSettingsFile()
	 */
	function getInstallSitePluginSettingsFile() {
		return $this->getPluginPath() . '/settings.xml';
	}

	/**
	 * @see Plugin::isSitePlugin()
	 */
	function isSitePlugin() {
		return true;
	}

	//
	// Implement template methods from GenericPlugin.
	//
	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $actionArgs) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled() ? array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, array_merge($actionArgs, array('verb' => 'settings'))),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			) : array(),
			parent::getActions($request, $actionArgs)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		switch ($request->getUserVar('verb')) {
			case 'settings':
				// Instantiate the settings form.
				$this->import('classes.form.AlgoliaSettingsForm');
				$form = new AlgoliaSettingsForm($this);

				// Handle request to save configuration data.
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();

						// Index rebuild.
						if ($request->getUserVar('rebuildIndex')) {
							// Check whether we got valid index rebuild options.
							if ($form->validate()) {
								// Check whether a journal was selected.
								$journal = null;
								$journalId = $request->getUserVar('journalToReindex');
								if (!empty($journalId)) {
									/* @var $journalDao JournalDAO */
									$journalDao = DAORegistry::getDAO('JournalDAO');
									$journal = $journalDao->getById($journalId);
									if (!is_a($journal, 'Journal')) $journal = null;
								}
								if (empty($journalId) || (!empty($journalId) && is_a($journal, 'Journal'))) {
									// Rebuild index.
									$messages = null;
									$this->_rebuildIndex(false, $journal, true, $messages);

									// Transfer indexing output to the form template.
									$form->setData('rebuildIndexMessages', $messages);

									return new JSONMessage(true, $form->fetch($request));
								}
							}
						}

						return new JSONMessage(true);
					}
				} else {
					// Re-init data. It should be visible to users
					// that whatever data they may have entered into
					// the form was not saved.
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * Add properties used for tracking publication indexing state to the entity's
	 * list for storage in the database.
	 *
	 * @param $hookName string `Schema::get::publication`
	 * @param $params array
	 *
	 * @return bool
	 */
	public function addToPublicationSchema($hookName, $params) {
		$schema =& $params[0];

		$schema->properties->{'algoliaIndexingState'} = (object) [
			'type' => 'boolean',
			'validation' => ['nullable'],
		];

		return false;
	}

	/**
	 * Run app-specific query builder methods for getMany
	 *
	 * @param $hookName string
	 * @param $params array [
	 * @option \APP\Services\QueryBuilders\PublicationQueryBuilder
	 * @option array Request args
	 * ]
	 *
	 * @return bool
	 */
	public function modifyPublicationQueryBuilder($hookName, $params) {
		// This is for modifying the query builder, i.e. to add a filter
		$publicationQB =& $params[0];
		$requestArgs = $params[1];

		if (!empty($requestArgs['algoliaIndexingState'])) {
			$algoliaIndexingState = $requestArgs['algoliaIndexingState'];
			$publicationQB->algoliaIndexingState = $algoliaIndexingState;
		}

		return false;
	}

	/**
	 * Add app-specific query statements to the publication getMany query
	 *
	 * @param $hookName string
	 * @param $params array [
	 * @option object $queryObject
	 * @option \APP\Services\QueryBuilders\PublicationQueryBuilder $queryBuilder
	 * ]
	 *
	 * @return bool
	 */
	public function modifyPublicationQueryObject($hookName, $params) {
		// Include desired query into the query objects, i.e. to include added filter
		$queryObject =& $params[0];
		$queryBuilder = $params[1];

		if (!empty($queryBuilder->algoliaIndexingState)) {
			$algoliaIndexingState = $queryBuilder->algoliaIndexingState;

			$queryObject->leftJoin('publication_settings as ps', 'p.publication_id', '=', 'ps.publication_id')
				->where('ps.setting_name', '=', 'algoliaIndexingState')
				->where('ps.setting_value', '=', $algoliaIndexingState);
		}

		return true;
	}

	//
	// Data-access level hook implementations.
	//
	/**
	 * Published articles where the metadata is changed will fire this hook.
	 * We need to immediately call pushChangedArticles because the change are
	 * already live.
	 * @see ArticleSearchIndex::articleMetadataChanged()
	 *
	 * @param $hookName 'ArticleSearchIndex::articleMetadataChanged'
	 * @param $params array '[Submission]'
	 *
	 * @return bool
	 */
	function callbackArticleMetadataChanged($hookName, $params) {
		assert($hookName == 'ArticleSearchIndex::articleMetadataChanged');
		/* @var $submission Submission */
		$submission = $params[0];

		$publication = $submission->getCurrentPublication();
		if (is_a($publication, 'Publication')) {
			$this->_algoliaService->markArticleChanged($publication);
			$this->_algoliaService->pushChangedArticles(5);
		}

		return true;
	}

	/**
	 * @see ArticleSearchIndex::articleDeleted()
	 */
	function callbackArticleDeleted($hookName, $params) {
		assert($hookName == 'ArticleSearchIndex::articleDeleted');
		$submissionId = $params[0];


		// Deleting an article must always be done synchronously
		// (even in pull-mode) as we'll no longer have an object
		// to keep our change information.
		$this->_algoliaService->deleteArticleFromIndex($submissionId);
		return true;
	}

	/**
	 * Marks all publications associated with submission as dirty so they can be
	 * handled by AlgoliaService::pushChangedArticles()
	 *
	 * @param $hookName String
	 * @param $params array [
	 * @option $newPublication Publication
	 * @option $oldPublication Publication
	 * @option $submission Submission
	 * ]
	 */
	function callbackPublicationStatusChanged($hookName, $params) {
		$submission = $params[2];

		$publicationsIterator = Services::get('publication')->getMany(['submissionIds' => $submission->getId()]);
		foreach ($publicationsIterator as $publication) {
			$this->_algoliaService->markArticleChanged($publication);
		}
	}

	/**
	 * @see ArticleSearchIndex::articleChangesFinished()
	 *
	 * This fires after an article has been published
	 */
	function callbackArticleChangesFinished($hookName, $params) {
		// In the case of pull-indexing we ignore this call
		// and let the Solr server initiate indexing.
		// if ($this->getSetting(CONTEXT_SITE, 'pullIndexing')) return true;

		// If the plugin is configured to push changes to the
		// server then we'll now batch-update all articles that
		// changed since the last update. We use a batch size of 5
		// for online index updates to limit the time a request may be
		// locked in case a race condition with a large index update
		// occurs.
		$algoliaService = $this->getAlgoliaService();
		$algoliaService->pushChangedArticles(5);

		return true;
	}

	/**
	 * @see ArticleSearchIndex::rebuildIndex()
	 */
	function callbackRebuildIndex($hookName, $params) {
		assert($hookName == 'ArticleSearchIndex::rebuildIndex');

		// Unpack the parameters.
		list($log, $journal, $switches) = $params;

		// Check switches.
		$rebuildIndex = true;

		if (is_array($switches)) {
			if (in_array('-n', $switches)) {
				$rebuildIndex = false;
			}
		}

		// Rebuild the index.
		$messages = null;
		$this->_rebuildIndex($log, $journal, $rebuildIndex, $messages);
		return true;
	}

	/**
	 * @see ArticleSearchIndex::submissionFileDeleted()
	 */
	function callbackSubmissionFileDeleted($hookName, $params) {
		assert($hookName == 'ArticleSearchIndex::submissionFileDeleted');
		$submissionId = $params[0];

		$this->_algoliaService->deleteArticleFromIndex($submissionId);
		return true;
	}

	//
	// Private helper methods
	//
	/**
	 * Rebuild the index for all journals or a single journal
	 *
	 * @param $log boolean Whether to write the log to standard output.
	 * @param $journal Journal If given, only re-index this journal.
	 * @param $buildIndex boolean Whether to rebuild the journal index.
	 * @param $messages string Return parameter for log message output.
	 * @return boolean True on success, otherwise false.
	 */
	function _rebuildIndex($log, $journal, $buildIndex, &$messages) {
		// TODO: Clean up
		// Rebuilding the index can take a long time.
		@set_time_limit(0);
		$algoliaService = $this->getAlgoliaService();

		if ($buildIndex) {
			// If we got a journal instance then only re-index
			// articles from that journal.
			$journalIdOrNull = (is_a($journal, 'Journal') ? $journal->getId() : null);

			// Clear index (if the journal id is null then
			// all journals will be deleted from the index).
			$this->_indexingMessage($log, 'AlgoliaPlugin: ' . __('search.cli.rebuildIndex.clearingIndex') . ' ... ', $messages);

			$this->_indexingMessage($log, __('search.cli.rebuildIndex.done') . PHP_EOL, $messages);

			// Re-build index, either of a single journal...
			if (is_a($journal, 'Journal')) {
				$journals = array($journal);
				unset($journal);
				// ...or for all journals.
			} else {
				/* @var $journalDao JournalDAO */
				$journalDao = DAORegistry::getDAO('JournalDAO');
				$journalIterator = $journalDao->getAll();
				// TODO: Is it necessary to convert iterator to array? Don't think so. Check.
				$journals = $journalIterator->toArray();
			}

			// We re-index journal by journal to partition the task a bit
			// and provide better progress information to the user.
			foreach ($journals as $journal) {
				$this->_indexingMessage($log, 'AlgoliaPlugin: ' . __('search.cli.rebuildIndex.indexing', array('journalName' => $journal->getLocalizedName())) . ' ', $messages);

				// Mark all articles in the journal for re-indexing.
				$numMarked = $this->_algoliaService->markJournalChanged($journal->getId());
				$algoliaService->pushChangedArticles(ALGOLIA_INDEXING_MAX_BATCHSIZE, $journal->getId());

				// TODO: Note: $numMarked listed as $numIndex in 3.1.x version. Did not exist.
				$this->_indexingMessage($log, ' ' . __('search.cli.rebuildIndex.result', array('numIndexed' => $numMarked)) . PHP_EOL, $messages);
			}
		}

		$this->_indexingMessage($log, __('search.cli.rebuildIndex.done') . PHP_EOL, $messages);

		return true;
	}

	/**
	 * Output an indexing message.
	 *
	 * @param $log boolean Whether to write the log to standard output.
	 * @param $message string The message to display/add.
	 * @param $messages string Return parameter for log message output.
	 */
	function _indexingMessage($log, $message, &$messages) {
		if ($log) echo $message;
		$messages .= $message;
	}
}

