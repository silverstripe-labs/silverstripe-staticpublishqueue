<?php

namespace SilverStripe\StaticPublishQueue\Extension\Publishable;

use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\ORM\DataExtension;
use SilverStripe\StaticPublishQueue\Contract\StaticallyPublishable;
use SilverStripe\StaticPublishQueue\Contract\StaticPublishingTrigger;

/**
 * Bare-bones implementation of a publishable page.
 *
 * You can override this either by implementing one of the interfaces the class directly, or by applying
 * an extension via the config system ordering (inject your extension "before" the PublishableSiteTree).
 *
 * @TODO: re-implement optional publishing of all the ancestors up to the root? Currently it only republishes the parent
 *
 * @see SiteTreePublishingEngine
 */
class PublishableSiteTree extends DataExtension implements StaticallyPublishable, StaticPublishingTrigger
{
    public function getMyVirtualPages()
    {
        return VirtualPage::get()->filter(['CopyContentFrom.ID' => $this->owner->ID]);
    }

    /**
     * @param array $context
     * @return array
     */
    public function objectsToUpdate($context)
    {
        $list = [];
        switch ($context['action']) {
            case 'publish':
                // Trigger refresh of the page itself.
                $list[] = $this->getOwner();

                // Refresh the parent.
                if ($this->getOwner()->ParentID) {
                    $list[] = $this->getOwner()->Parent();
                }

                // Refresh related virtual pages.
                $virtuals = $this->getOwner()->getMyVirtualPages();
                if ($virtuals->exists()) {
                    foreach ($virtuals as $virtual) {
                        $list[] = $virtual;
                    }
                }
                break;

            case 'unpublish':
                // Refresh the parent
                if ($this->getOwner()->ParentID) {
                    $list[] = $this->getOwner()->Parent();
                }
                break;
        }
        return $list;
    }

    /**
     * @param array $context
     * @return array
     */
    public function objectsToDelete($context)
    {
        $list = [];
        switch ($context['action']) {
            case 'unpublish':
                // Trigger cache removal for this page.
                $list[] = $this->getOwner();

                // Trigger removal of the related virtual pages.
                $virtuals = $this->getOwner()->getMyVirtualPages();
                if ($virtuals->exists()) {
                    foreach ($virtuals as $virtual) {
                        $list[] = $virtual;
                    }
                }
                break;
        }
        return $list;
    }

    /**
     * The only URL belonging to this object is it's own URL.
     * Instead of Link() which calls VersionedStateExtension::updateLink and appends stage=Live
     */
    public function urlsToCache()
    {
        $relativeLink = $this->getOwner()->RelativeLink();
        $link =  Controller::join_links(Director::baseURL(), $relativeLink);

        return [Director::absoluteURL($link) => 0];
    }
}
