<?php
/**
 * @package silverstripe-multisites
 */
class MultisitesSiteTreeExtension extends SiteTreeExtension {

	private static $has_one = array(
		'Site' => 'Site'
	);

	/**
	 * @return Site
	 */
	public function CurrentSite() {
		return Multisites::inst()->getCurrentSite();
	}

	/**
	 * @return Site
	 */
	public function DefaultSite() {
		return Multisites::inst()->getDefaultSite();
	}

	public function updateCMSFields(FieldList $fields) {
		if($this->owner->ParentID) {
			$url = $this->owner->Parent()->AbsoluteLink();
			if(!$url){
				$url = $this->owner->Parent()->getURL() . '/';
			}
		} else {
			$url = Director::absoluteBaseURL();
		}


		$fields->dataFieldByName('URLSegment')->setURLPrefix($url);
	}
	
	/**
	 * Make sure site home pages are loaded at the root of the site.
	 */
	public function contentcontrollerInit($controller) {
		
		// If we've accessed the homepage as /home/, then we should redirect to /.
		if($controller->dataRecord && $controller->dataRecord instanceof SiteTree
			 	&& MultisitesRootController::should_be_on_root($controller->dataRecord) && (!isset($controller->urlParams['Action']) || !$controller->urlParams['Action'] ) 
				&& !$_POST && !$_FILES && !$controller->redirectedTo() ) {
			$getVars = $_GET;
			unset($getVars['url']);
			if($getVars) $url = "?" . http_build_query($getVars);
			else $url = "";
			$controller->redirect($url, 301);
			return;
		}
	}

	public function validate(ValidationResult $result) {
		$this->setupTest();
	}

	/**
	 * Sets up the 'Site' record in-place while running 'cms/tests' and others.
	 */
	private function setupTest() {
		if (!SapphireTest::is_running_test()) {
			return;
		}
		if ($this->owner->SiteID || $this->owner instanceof Site) {
			return;
		}
		$site = DataObject::get_by_id('Site', (int)Multisites::inst()->getDefaultSiteId());
		//Debug::dump($siteID);
		if(!$site || !$site->exists()) {
			static $inSetupTest = false;
			if ($inSetupTest === false) {
				$inSetupTest = true;
				$site = Site::create();
				if (!($site instanceof Site)) {
					throw new Exception('"Site" class not found.');
				}
				// NOTE(Jake): SiteTreeBacklinksTest.yml sets the ID of a page explictly, so we need to ensure
				//			   there isn't a clash.
				$site->ID = 1000000;
				$site->Title = _t('Multisites.DEFAULTSITE', 'Default Site');
				$site->IsDefault = true;
				$site->write();
				$site->publish('Stage', 'Live');
				$inSetupTest = false;
				Multisites::inst()->init();
			}

			/*static $inOnBeforeWriteCall = false;
			if ($inOnBeforeWriteCall !== false) {
				return;
			}
			$inOnBeforeWriteCall = true;
			singleton('Site')->requireDefaultRecords();
			$inOnBeforeWriteCall = false;

			$site = DataObject::get_by_id('Site', (int)Multisites::inst()->getDefaultSiteId());*/
		}

		$this->owner->SiteID = (int)Multisites::inst()->getDefaultSiteId();
		if (!$this->owner->ParentID) {
			$this->owner->ParentID = $this->owner->SiteID;
		}
	}

	/**
	 * Keep the SiteID field consistent.
	 */
	public function onBeforeWrite() {
		if ($this->owner instanceof Site) {
			if(!$this->owner->ID) {
				// Initialise a new Site to the top level
				$this->owner->SiteID = 0;
				$this->owner->ParentID = 0;
			}
			return;
		}

		// NOTE: When building fixtures during unit tests, validation is disabled, so we must
		//		 call this function here as well.
		$this->setupTest();

		// Set the SiteID (and ParentID if required) for all new pages.
		if(!$this->owner->ID) {
			$parent = $this->owner->Parent();
			if ($parent && $parent->exists()) {
				// Page is beneath a Site
				if($parent instanceof Site) {
					$this->owner->SiteID = $parent->ID;
				} else {
					$this->owner->SiteID = $parent->SiteID;
				}
			} else {
				// Create the page beneath the current Site
				$this->owner->SiteID = Multisites::inst()->getDefaultSiteId();
				$this->owner->ParentID = $this->owner->SiteID;
			}
		}
		
		// Make sure SiteID is changed when site tree is reorganised.
		if ($this->owner->ID && $this->owner->isChanged('ParentID')) {
			// Get the new parent
			$parent = DataObject::get_by_id('SiteTree', $this->owner->ParentID);
			
			// Make sure the parent exists
			if ( $parent ) {
				// Recursively change SiteID for this and all child pages
				$siteId = ($parent instanceof Site) ? $parent->ID : $parent->SiteID;
				$this->owner->updateSiteID($siteId);
			}	
		}

		//if (!$this->owner->SiteID || !$this->owner->Site()->exists()) {
		//	throw new Exception('Missing SiteID. Current site ID is: '.Multisites::inst()->getDefaultSiteId());
		//}
	}
	
	/**
	 * Recursively update the site ID for this page and all child pages. This writes decendents to the
	 * database, but does not write the current page as it is called from {@link onBeforeWrite}
	 * 
	 * @todo This will mark all child pages as modified. Should it write directly to the database to avoid the publishing workflow?
	 * 
	 * @param int $new The new SiteID
	 */
	public function updateSiteID($new) {
		if ($this->owner->SiteID != $new) {
			$this->owner->SiteID = $new;
			if ($this->owner->isChanged('SiteID')) {
				foreach ($this->owner->Children() as $child) {
					$child->updateSiteID($new);
					$child->write();
				}
			}
		}
	}

	public function alternateAbsoluteLink($action = null) {
		if($this->owner->SiteID && $this->owner->SiteID == Multisites::inst()->getCurrentSiteId()) {
			return Director::absoluteURL($this->owner->Link($action));
		} else {
			return $this->owner->RelativeLink($action);
		}
	}
	
	/**
	 * Returns the current site object in case this is a fake page (like in the case of  pages served 
	 * by the {@link Security} controller)
	 * 
	 * @return Site
	 */
	public function getSite() {
		$site = $this->owner->getComponent('Site');
		return ($site->ID) ? $site : Multisites::inst()->getCurrentSite();
	}

}
