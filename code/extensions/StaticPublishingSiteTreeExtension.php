<?php
class StaticPublishingSiteTreeExtension extends DataExtension {

	//include all ancestor pages in static publishing queue build, or just one level of parent
	public static $includeAncestors = true;

	function onAfterPublish() {
		$urls = $this->pagesAffected();
		if(!empty($urls)) URLArrayObject::add_urls_on_behalf($urls, $this->owner);
	}

	function onAfterUnpublish() {
		//get all pages that should be removed
		$removePages = $this->owner->pagesToRemoveAfterUnpublish();
		$updateURLs = array();  //urls to republish
		$removeURLs = array();  //urls to delete the static cache from
		foreach($removePages as $page) {
			if ($page instanceof RedirectorPage) {
				$link = $page->regularLink();
			} else {
				$link = $page->Link();
			}
			$removeURLs[] = $link;
			//and update any pages that might have been linking to those pages
			$updateURLs = array_merge((array)$updateURLs, (array)$page->pagesAffected(true));
		}

		increase_time_limit_to();
		increase_memory_limit_to();
		singleton("SiteTree")->unpublishPagesAndStaleCopies($removeURLs); //remove those pages (right now)

		if(!empty($updateURLs)) URLArrayObject::add_urls_on_behalf($updateURLs, $this->owner);
	}

	/**
	 * Removes the unpublished page's static cache file as well as its 'stale.html' copy.
	 * Copied from: FilesystemPublisher->unpublishPages($urls)
	 */
	public function unpublishPagesAndStaleCopies($urls) {
		// Detect a numerically indexed arrays
		if (is_numeric(join('', array_keys($urls)))) $urls = $this->owner->urlsToPaths($urls);

		$cacheBaseDir = $this->owner->getDestDir();

		foreach($urls as $url => $path) {
			if (file_exists($cacheBaseDir.'/'.$path)) {
				@unlink($cacheBaseDir.'/'.$path);
			}
			$lastDot = strrpos($path, '.'); //find last dot
			if ($lastDot !== false) {
				$stalePath = substr($path, 0, $lastDot) . '.stale' . substr($path, $lastDot);
				if (file_exists($cacheBaseDir.'/'.$stalePath)) {
					@unlink($cacheBaseDir.'/'.$stalePath);
				}
			}
		}
	}

	function pagesToRemoveAfterUnpublish() {
		$pages = array();
		$pages[] = $this->owner;

		// Including VirtualPages with reference this page
		$virtualPages = VirtualPage::get()->filter(array('CopyContentFromID' => $this->owner->ID));
		if ($virtualPages->Count() > 0) {
			foreach($virtualPages as $virtualPage) {
				$pages[] = $virtualPage;
			}
		}

		// Including RedirectorPages with reference this page
		$redirectorPages = RedirectorPage::get()->filter(array('LinkToID' => $this->owner->ID));
		if($redirectorPages->Count() > 0) {
			foreach($redirectorPages as $redirectorPage) {
				$pages[] = $redirectorPage;
			}
		}

		$this->owner->extend('extraPagesToRemove',$this->owner, $pages);

		return $pages;
	}

	/**
	 * Provides a list of URLs that need to be refreshed as a result of this page being changed.
	 * This is intended for partial site updates, such as publishing one page from the CMS.
	 *
	 * This may be different from subPagesToCache - could be a smaller set if we intend to leave some
	 * parts of the cache stale, or a larger set if we have a lot of related urls to update.
	 *
	 * @return array associative array of url => priority
	 */
	function pagesAffected($unpublish = false) {
		$urls = array();
		if ($this->owner->hasMethod('pagesAffectedByChanges')) {
			$urls = $this->owner->pagesAffectedByChanges();
		}

		$oldMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Live');

		//the the live version of the current page
		if ($unpublish) {
			//We no longer have access to the live page, so can just try to grab the ParentID.
			$thisPage = SiteTree::get()->byID($this->owner->ParentID);
		} else {
			$thisPage = SiteTree::get()->byID($this->owner->ID);
		}

		if ($thisPage) {
			//include any related pages (redirector pages and virtual pages)
			$urls = array_merge((array)$urls, (array)$thisPage->subPagesToCache());
			if($thisPage instanceof RedirectorPage){
				$link = $thisPage->regularLink();
				$urls = array_merge((array)$urls, array($link));
			}
		}

		Versioned::set_reading_mode($oldMode);
		$this->owner->extend('extraPagesAffected',$this->owner, $urls);

		return $urls;
	}

	/**
	 * Get a list of URLs related to this page as needed for regenerating the cache from scratch.
	 * The sum of all subPagesToCache as executed on SiteTree objects must cover all reachable URLs for this site.
	 *
	 * Do not include URLs that should better belong to another object, as this will cause overlap during
	 * the rebuild-all.
	 *
	 * @return array associative array of url => priority
	 */
	function subPagesToCache() {
		$urls = array();

		// Add redirector page (if required) or just include the current page
		if($this->owner instanceof RedirectorPage) {
			$link = $this->owner->regularLink();
		} else {
			$link = $this->owner->Link();
		}
		$urls[$link] = 60;

		//include the parent and the parent's parents, etc
		$parent = $this->owner->Parent();
		if(!empty($parent) && $parent->ID > 0) {
			if (self::$includeAncestors) {
				$links = (array)$parent->subPagesToCache();
			} else {
				$link = $parent->Link();
				$links = array($link);
			}
			$urls = array_merge((array)$urls, $links);
		}

		// Including VirtualPages with this page as an original
		$virtualPages = VirtualPage::get()->filter(array('CopyContentFromID' => $this->owner->ID));
		if ($virtualPages->Count() > 0) {
			foreach($virtualPages as $virtualPage) {
				$urls = array_merge((array)$urls, (array)$virtualPage->subPagesToCache());
				if($p = $virtualPage->Parent) $urls = array_merge((array)$urls, (array)$p->subPagesToCache());
			}
		}

		// Including RedirectorPage
		$redirectorPages = RedirectorPage::get()->filter(array('LinkToID' => $this->owner->ID));
		if($redirectorPages->Count() > 0) {
			foreach($redirectorPages as $redirectorPage) {
				$link = $redirectorPage->regularLink();
				$urls[] = $link;
			}
		}

		$this->owner->extend('extraSubPagesToCache',$this->owner, $urls);

		return $urls;
	}

	/**
	 * Overriding the static publisher's default functionality to run our on unpublishing logic. This needs to be
	 * here to satisfy StaticPublisher's method call
	 * @return array
	 */
	function allPagesToCache() {
		if (method_exists($this->owner,'allPagesToCache')) {
			return $this->owner->allPagesToCache();
		} else {
			return array();
		}
	}
}
