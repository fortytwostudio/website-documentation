<?php
namespace fortytwostudio\websitedocumentation\services;

use yii\base\Component;

use Craft;
use fortytwostudio\websitedocumentation\elements\GuideEntry;
use fortytwostudio\websitedocumentation\WebsiteDocumentation;

class ReturnSettings extends Component
{
	public function guideSection()
	{
		$settings = WebsiteDocumentation::$settings;

		if (!$settings->structureUid) {
			$query = GuideEntry::find()
				->one();

			if ($query->structureId)
			{
				$structure = Craft::$app->getStructures()->getStructureById($query->structureId);

				$settings = WebsiteDocumentation::$plugin->getSettings()->getAttributes();
				$settings['structureUid'] = $structure->uid;

				// Save!
				Craft::$app->getPlugins()->savePluginSettings(WebsiteDocumentation::$plugin, $settings);
			}
		}

		$structure = Craft::$app->structures->getStructureByUid($settings->structureUid)?->id;

		return $structure;
	}

	public function formatContent($content)
	{
		// Format Tab Buttons to work with JS
		$newContent = preg_replace_callback('/<p .*?class="tab-button">(.*?)<\/p>/',
			function ($name) {
				$tab = strtolower(preg_replace('/\s+/', '-', $name[1]));
				return '<button class="tab mb-4 block underline" role="tab" data-tab-inner="guide" data-tab="'. $tab .'" data-hash="'. $tab .'">'. $name[1] .'</button>';
			},
			$content
		);

		return $newContent;

	}
}
