<?php

/**
 * @file plugins/generic/algolia/classes/AlgoliaService.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AlgoliaService
 * @ingroup plugins_generic_algolia_classes
 *
 * @brief Indexes content into Algolia
 *
 * This class relies on Composer, the PHP curl and mbstring extensions. Please
 * install Composer and activate the extension before trying to index content into Algolia
 */

// Flags used for index maintenance.
define('ALGOLIA_INDEXINGSTATE_DIRTY', true);
define('ALGOLIA_INDEXINGSTATE_CLEAN', false);

// The max. number of articles that can
// be indexed in a single batch.
define('ALGOLIA_INDEXING_MAX_BATCHSIZE', 2000);

// Number of words to split
define('ALGOLIA_WORDCOUNT_SPLIT', 250);

import('classes.search.ArticleSearch');
import('plugins.generic.algolia.classes.AlgoliaEngine');
import('lib.pkp.classes.config.Config');

class AlgoliaService {
	var $indexer = null;

	/**
	 * Constructor
	 *
	 * @param boolean $settingsArray Settings for instantiating AlgoliaEngine
	 */
	function __construct($settingsArray = false) {
		if (!$settingsArray) {
			return false;
		}

		$this->indexer = new AlgoliaEngine($settingsArray);
	}

	//
	// Getters and Setters
	//
	/**
	 * Retrieve a journal (possibly from the cache).
	 *
	 * @param $journalId int
	 * @return Journal
	 */
	function _getJournal($journalId) {
		if (isset($this->_journalCache[$journalId])) {
			$journal = $this->_journalCache[$journalId];
		} else {
			/* @var $journalDao JournalDAO */
			$journalDao = DAORegistry::getDAO('JournalDAO');
			$journal = $journalDao->getById($journalId);
			$this->_journalCache[$journalId] = $journal;
		}

		return $journal;
	}

	/**
	 * Retrieve an issue (possibly from the cache).
	 *
	 * @param $issueId int
	 * @param $journalId int
	 * @return Issue
	 */
	function _getIssue($issueId, $journalId) {
		if (isset($this->_issueCache[$issueId])) {
			$issue = $this->_issueCache[$issueId];
		} else {
			/* @var $issueDao IssueDAO */
			$issueDao = DAORegistry::getDAO('IssueDAO');
			$issue = $issueDao->getById($issueId, $journalId, true);
			// TODO: _issueCache does not exist. Check if problem.
			$this->_issueCache[$issueId] = $issue;
		}

		return $issue;
	}


	//
	// Public API
	//
	/**
	 * Mark a single article "changed" so that the indexing
	 * back-end will update it during the next batch update.
	 *
	 * @param $publication Publication
	 */
	function markArticleChanged($publication) {
		if (!is_a($publication, 'Publication')) {
			assert(false);
			return;
		}

		Services::get('publication')->edit(
			$publication,
			['algoliaIndexingState' => ALGOLIA_INDEXINGSTATE_DIRTY],
			Application::get()->getRequest());
	}

	/**
	 * Mark the given journal for re-indexing.
	 *
	 * @param $journalId integer The ID of the journal to be (re-)indexed.
	 * @return integer The number of articles that have been marked.
	 */
	function markJournalChanged($journalId) {
		if (!is_numeric($journalId)) {
			assert(false);
			return;
		}

		$articlesChanged = 0;

		// Retrieve all articles of the journal.
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		/* @var $submissionDao SubmissionDAO */
		$submissions = $submissionDao->getByContextId($journalId);

		// Run through the articles and mark them "changed".
		while ($submission = $submissions->next()) {
			$publication = $submission->getCurrentPublication();
			if (is_a($publication, 'Publication')) {
				if ($publication->getData('status') == STATUS_PUBLISHED) {
					$this->markArticleChanged($publication, $journalId);
					$articlesChanged++;
				}
			}
		}

		return $articlesChanged;
	}

	/**
	 * (Re-)indexes all changed articles in Algolia.
	 *
	 * This is 'pushing' the content to Algolia.
	 *
	 * To control memory usage and response time we
	 * index articles in batches. Batches should be as
	 * large as possible to reduce index commit overhead.
	 *
	 * @param $batchSize integer The maximum number of articles
	 *  to be indexed in this run.
	 * @param $journalId integer If given, restrains index
	 *  updates to the given journal.
	 */
	function pushChangedArticles($batchSize = ALGOLIA_INDEXING_MAX_BATCHSIZE, $journalId = null) {
		// Retrieve a batch of "changed" articles.
		$queryParams = array(
			'algoliaIndexingState' => ALGOLIA_INDEXINGSTATE_DIRTY,
			'contextIds' => is_null($journalId) ? [] : [$journalId],
			'count' => $batchSize
		);
		$changedArticleIterator = Services::get('publication')->getMany($queryParams);

		$toDelete = array();
		$toAdd = array();

		foreach ($changedArticleIterator as $indexedArticle) {
			$indexedArticle = Services::get('publication')->edit(
				$indexedArticle,
				['algoliaIndexingState' => ALGOLIA_INDEXINGSTATE_CLEAN],
				Application::get()->getRequest());

			$toDelete[] = $this->buildAlgoliaObjectDelete($indexedArticle);

			// Only add/re-add if indexedArticle still has STATUS_PUBLISHED
			// Check if publication is current, don't add if not
			if ($indexedArticle->getData('status') == STATUS_PUBLISHED && $this->_isCurrentPublication($indexedArticle)) {
				$toAdd[] = $this->buildAlgoliaObjectAdd($indexedArticle);
			}
		}

		if ($journalId) {
			unset($toDelete);
			$this->indexer->clear_index();
		} else {
			foreach ($toDelete as $delete) {
				$this->indexer->deleteByDistinctId($delete['body']['distinctId']);
			}
		}

		foreach ($toAdd as $add) {
			$this->indexer->index($add);
		}
	}

	/**
	 * Deletes the given article (submission) from Algolia.
	 * NB: Loops through all published publications associated with a submission.
	 *
	 * @param $submissionId integer The ID of the article (submission) to be deleted.
	 *
	 * @return boolean true if successful, otherwise false.
	 */
	function deleteArticleFromIndex($submissionId) {
		if (!is_numeric($submissionId)) {
			assert(false);
			return;
		}

		$toDelete = array();

		/* @var SubmissionDAO */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($submissionId);
		$publicationIds = $submission->getPublishedPublications();

		foreach ($publicationIds as $publicationId) {
			$toDelete[] = $this->buildAlgoliaObjectDelete($publicationId);
		}

		foreach ($toDelete as $delete) {
			$this->indexer->deleteByDistinctId($delete['body']['distinctId']);
		}

		return true;
	}

	/**
	 * Deletes all articles of a journal or of the
	 * installation from Algolia.
	 *
	 * @param $journalId integer If given, only articles
	 *  from this journal will be deleted.
	 * @return boolean true if successful, otherwise false.
	 * @deprecated Never called. Will be removed.
	 */
	function deleteArticlesFromIndex($journalId = null) {
		// Delete only articles from one journal if a
		// journal ID is given.
		$journalQuery = '';
		if (is_numeric($journalId)) {
			$journalQuery = ' AND journal_id:' . $this->_instId . '-' . $journalId;
		}

		// Delete all articles of the installation (or journal).
		$xml = '<query>inst_id:' . $this->_instId . $journalQuery . '</query>';
		return $this->_deleteFromIndex($xml);
	}

	/**
	 * Returns an array with all (dynamic) fields in the index.
	 *
	 * NB: This is cached data so after an index update we may
	 * have to flush the index to re-read the current index state.
	 *
	 * @param $fieldType string Either 'search' or 'sort'.
	 * @return array
	 */
	function getAvailableFields($fieldType) {
		// TODO: Never called
		$cache = $this->_getCache();
		$fieldCache = $cache->get($fieldType);
		return $fieldCache;
	}

	/**
	 * Return a list of all text fields that may occur in the
	 * index.
	 *
	 * @param $fieldType string "search", "sort" or "all"
	 *
	 * @return array
	 */
	function _getFieldNames() {
		// TODO: Investigate if this is where indexing keywords should happen
		return array(
			'localized' => array(
				'title', 'abstract', 'discipline', 'subject',
				'keyword', 'type', 'coverage',
			),
			'multiformat' => array(
				'galleyFullText'
			),
			'static' => array(
				'authors' => 'authors_txt',
				'publicationDate' => 'publicationDate_dt'
			)
		);
	}

	/**
	 * HAS NOT BEEN UPDATED FOR 3.2.
	 * Check whether access to the given article
	 * is authorized to the requesting party (i.e. the
	 * Solr server).
	 *
	 * @param $article Article
	 * @return boolean True if authorized, otherwise false.
	 * @deprecated Never called. Will be removed.
	 */
	function _isArticleAccessAuthorized($article) {
		// TODO: Unclear what this does. Should it use Submission or Publication??
		// TODO: Never called
		// Did we get a published article?
		if (!is_a($article, 'PublishedArticle')) return false;

		// Get the article's journal.
		$journal = $this->_getJournal($article->getJournalId());
		if (!is_a($journal, 'Journal')) return false;

		// Get the article's issue.
		$issue = $this->_getIssue($article->getIssueId(), $journal->getId());
		if (!is_a($issue, 'Issue')) return false;

		// Only index published articles.
		if (!$issue->getPublished() || $article->getStatus() != STATUS_PUBLISHED) return false;

		// Make sure the requesting party is authorized to access the article/issue.
		import('classes.issue.IssueAction');
		$issueAction = new IssueAction();
		$subscriptionRequired = $issueAction->subscriptionRequired($issue, $journal);
		if ($subscriptionRequired) {
			$isSubscribedDomain = $issueAction->subscribedDomain(Application::getRequest(), $journal, $issue->getId(), $article->getId());
			if (!$isSubscribedDomain) return false;
		}

		// All checks passed successfully - allow access.
		return true;
	}

	function buildAlgoliaObjectAdd($publication) {
		// mark the article as "clean"
		$publication = Services::get('publication')->edit(
			$publication,
			['algoliaIndexingState' => ALGOLIA_INDEXINGSTATE_CLEAN],
			Application::get()->getRequest());

		$baseData = array(
			"action" => "addObject",
			"body" => array(
				"distinctId" => (string) $publication->getId(),
			)
		);

		$objects = array();

		$articleData = $this->mapAlgoliaFieldsToIndex($publication);
		foreach ($articleData['body'] as $i => $chunks) {
			if (trim($chunks)) {
				$baseData['body']['objectID'] = $baseData['body']['distinctId'] . "_" . $i;
				$chunkedData = $articleData;
				$chunkedData['body'] = $chunks;
				$chunkedData['order'] = $i + 1;
				$baseData['body'] = array_merge($baseData['body'], $chunkedData);
				$objects[] = $baseData;
			}
		}

		return $objects;
	}

	function buildAlgoliaObjectDelete($publicationOrPublicationId) {
		// Wraps distinctId in `body` to keep consistent with expected Algolia list of operations
		// which expects only an `action`, `indexName`, and `body`.
		// See https://www.algolia.com/doc/api-reference/api-methods/batch/#method-param-operations
		if (!is_numeric($publicationOrPublicationId)) {
			return array(
				"action" => "deleteObject",
				"body" => array(
					"distinctId" => $publicationOrPublicationId->getId()
				)
			);
		}

		return array(
			"action" => "deleteObject",
			"body" => array(
				"distinctId" => $publicationOrPublicationId
			)
		);
	}

	function getAlgoliaFieldsToIndex() {
		$fieldsToIndex = array();

		$fields = $this->_getFieldNames();
		foreach (array('localized', 'multiformat', 'static') as $fieldSubType) {
			if ($fieldSubType == 'static') {
				foreach ($fields[$fieldSubType] as $fieldName => $dummy) {
					$fieldsToIndex[] = $fieldName;
				}
			} else {
				foreach ($fields[$fieldSubType] as $fieldName) {
					$fieldsToIndex[] = $fieldName;
				}
			}
		}

		return $fieldsToIndex;
	}

	function mapAlgoliaFieldsToIndex($publication) {
		$mappedFields = array();

		$fieldsToIndex = $this->getAlgoliaFieldsToIndex();
		foreach ($fieldsToIndex as $field) {
			switch ($field) {
				case "title":
					$mappedFields[$field] = $this->formatTitle($publication);
					break;

				case "abstract":
					$mappedFields[$field] = $this->formatAbstract($publication);
					break;

				case "discipline":
					$mappedFields[$field] = (array) $publication->getLocalizedData('disciplines', $publication->getData('locale'));
					break;

				case "subject":
					$mappedFields[$field] = (array) $publication->getLocalizedData('subjects', $publication->getData('locale'));
					break;

				case "keyword":
					$mappedFields[$field] = (array) $publication->getLocalizedData('keywords', $publication->getData('locale'));
					break;

				case "type":
					$mappedFields[$field] = $publication->getLocalizedData('type', $publication->getData('locale'));
					break;

				case "coverage":
					$mappedFields[$field] = (array) $publication->getLocalizedData('coverage', $publication->getData('locale'));
					break;

				case "galleyFullText":
					$mappedFields[$field] = $this->getGalleyHTML($publication);
					break;

				case "authors":
					$mappedFields[$field] = $this->getAuthors($publication);
					break;

				case "publicationDate":
					$mappedFields[$field] = strtotime($publication->getData('datePublished'));
					break;
			}
		}

		$mappedFields['section'] = $this->getSectionTitle($publication);
		$mappedFields['url'] = $this->formatUrl($publication);

		// combine abstract and galleyFullText into body and unset them
		$mappedFields['body'] = array_merge($mappedFields['abstract'], $mappedFields['galleyFullText']);
		unset($mappedFields['abstract']);
		unset($mappedFields['galleyFullText']);

		return $mappedFields;
	}

	/**
	 * @param $article
	 * @param false $custom
	 * @return false|string
	 * @deprecated Never called. Will be removed.
	 */
	function formatPublicationDate($article, $custom = false) {
		if (!$custom) {
			return $article->getDatePublished();
		} else {
			// for example:
			$publishedDate = date_create($article->getDatePublished());
			return date_format($publishedDate, "F Y");
		}
	}

	function formatUrl($publication) {
		$submission = Services::get('submission')->get($publication->getData('submissionId'));
		$submissionProperties = Services::get('submission')->getProperties(
			$submission,
			['urlPublished'],
			['request' => Application::get()->getRequest()]
		);
		return $submissionProperties['urlPublished'];

	}

	function getAuthors($publication) {
		$authorText = array();
		$publicationProperties = Services::get('publication')->getProperties(
			$publication,
			['authors'],
			['request' => Application::get()->getRequest()]);
		$authorsData = $publicationProperties['authors'];

		$authorDao = DAORegistry::getDAO('AuthorDAO');
		/* @var AuthorDAO */
		foreach ($authorsData as $authorData) {
			$author = $authorDao->getById($authorData['id']);
			$authorText[] = $author->getFullName();
		}

		return implode(", ", $authorText);
	}

	/**
	 * Gets section title string from a publication
	 *
	 * @param $publication Publication
	 * @return string
	 */
	function getSectionTitle($publication) {
		$sectionId = $publication->getData('sectionId');
		/* @var SectionDAO */
		$sectionDao = DAORegistry::getDAO('SectionDAO');
		$section = $sectionDao->getById($sectionId);
		return $section->getLocalizedTitle();
	}

	function formatAbstract($publication) {
		return $this->chunkContent($publication->getLocalizedData('abstract', $publication->getData('locale')));
	}

	function getGalleyHTML($publication) {
		$contents = "";

		$publicationProperties = Services::get('publication')->getProperties(
			$publication,
			['galleys'],
			['request' => Application::get()->getRequest()]);
		$galleysData = $publicationProperties['galleys'];

		/* @var ArticleGalleyDAO */
		$articleGalleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
		foreach ($galleysData as $galleyData) {
			$galley = $articleGalleyDao->getById($galleyData['id']);
			if ($galley->getFileType() == "text/html") {
				$submissionFile = $galley->getFile();
				$contents .= file_get_contents($submissionFile->getFilePath());
			}
		}

		return $this->chunkContent($contents);
	}

	function chunkContent($content) {
		$data = array();
		$updated_content = html_entity_decode($content);

		if ($updated_content) {
			$temp_content = str_replace("</p>", "", $updated_content);
			$chunked_content = preg_split("/<p[^>]*?(\/?)>/i", $temp_content);

			foreach ($chunked_content as $chunked) {
				if ($chunked) {
					$tagless_content = strip_tags($chunked);
					$data[] = trim(wordwrap($tagless_content, ALGOLIA_WORDCOUNT_SPLIT));
				}
			}
		} else {
			$data[] = trim(strip_tags($updated_content));
		}

		return $data;
	}

	function formatTitle($publication) {
		$title = $publication->getLocalizedTitle();

		return preg_replace("/<.*?>/", "", $title);
	}

	/**
	 * Checks if publication is submission's current publication
	 *
	 * @param $publication Publication
	 * @return bool
	 */
	private function _isCurrentPublication($publication) {
		$submission = Services::get('submission')->get($publication->getData('submissionId'));

		return $publication->getId() == $submission->getCurrentPublication()->getId();
	}
}
