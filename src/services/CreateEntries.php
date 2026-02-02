<?php
namespace fortytwostudio\websitedocumentation\services;

use yii\base\Component;

use Craft;
use fortytwostudio\websitedocumentation\elements\GuideEntry;
use craft\helpers\StringHelper;
use craft\web\View;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;
use fortytwostudio\websitedocumentation\data\DefaultEntries;

use Exception;

class CreateEntries extends Component
{
	public static function create(int $structureId, ?int $siteId = null)
	{
		// Get the sites
		if ($siteId == null) {
			$sites = Craft::$app->getSites();
			$editableSites = $sites->getEditableSiteIds();
		} else {
			$editableSites = [$siteId];
		}

		// Get our structure
		$settings = WebsiteDocumentation::$plugin->getSettings();
		$structure = Craft::$app->structures->getStructureByUid($settings->structureUid)?->id;

		// Get all Default Entries we want to add to the structure
		$entries = DefaultEntries::entries();

		// Loop through the sites and add entries is they don't exist
		foreach ($editableSites as $siteId)
		{

			// Check if entries exist for this site
			$entriesExist = GuideEntry::find()
				->siteId($siteId)
				->status(null)
				->all();

			if (!empty($entriesExist))
			{
				return true;
			}

			// Loop through the entries so we can create them
			foreach ($entries as $item) {
				// Get the slug of the item. We'll use this to check whether the page already exists & get the default content
				$slug = StringHelper::toKebabCase($item['title']);

				// Create a new element
				$entry = new GuideEntry([
					'siteId' => $siteId, // Ensure it's only being saved for this site
					'structureId' => $structure,
					'title' => $item['title'],
					'enabled' => true,
				]);

				// Check to see if any default content exists for this page
				if (Craft::$app->view->doesTemplateExist('websitedocumentation/content/' . $slug)) {
					// We need to render the template if it exists
					$defaultContent = Craft::$app->view->renderTemplate(
						'websitedocumentation/content/' . $slug
					);

					// If the data exists, lets add it to the field
					$entry->setFieldValues([
						'websiteDocumentationText' => $defaultContent,
					]);
				}

				// Save the entry
				$success = Craft::$app->elements->saveElement($entry, true, false);

				// If the save fails, lets throw an error
				if (!$success) {
					throw new Exception('Couldn’t save the entry "' . $entry->title . '"');
					return false;
				}

				// If children are set we need to loop through and save these as well
				if (isset($item['children'])) {
					foreach ($item['children'] as $child) {
						// Create a new element
						$childEntry = new GuideEntry([
							'siteId' => $siteId, // Ensure it's only being saved for this site
							'title' => $child,
							'parentId' => $entry->id,
							'enabled' => true,
						]);

						// Check to see if any default content exists for this page
						if (
							Craft::$app->view->doesTemplateExist(
								'websitedocumentation/content/' .
									$slug .
									'/' .
									StringHelper::toKebabCase($child)
							)
						) {
							// We need to render the template if it exists
							$defaultContent = Craft::$app->view->renderTemplate(
								'websitedocumentation/content/' .
									$slug .
									'/' .
									StringHelper::toKebabCase($child)
							);

							// If the data exists, lets add it to the field
							$childEntry->setFieldValues([
								'websiteDocumentationText' => $defaultContent,
							]);
						}

						// Save the entry
						$success = Craft::$app->elements->saveElement($childEntry, true, false);

						// If the save fails, lets throw an error
						if (!$success) {
							throw new Exception('Couldn’t save the entry: "' . $childEntry->title . '"');
							return false;
						}
					}
				}
			}
		}
	}
}
